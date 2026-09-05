<?php

declare(strict_types=1);

namespace cosmicpe\s3rp;

use Closure;
use Generator;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackInfoEntry;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\scheduler\BulkCurlTask;
use pocketmine\scheduler\BulkCurlTaskOperation;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\InternetException;
use pocketmine\utils\InternetRequestResult;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use WeakMap;
use function array_values;
use function basename;
use function bin2hex;
use function count;
use function current;
use function date;
use function filter_var;
use function fnmatch;
use function is_array;
use function is_string;
use function microtime;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtotime;
use function time;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const FILTER_VALIDATE_URL;

final class Loader extends PluginBase{

	/** @var array<string, array{string, string, string, int}> */
	private array $cdn_refresh = [];

	/** @var list<string> */
	private array $resource_pack_patterns;

	/** @var list<Closure() : void>|null */
	private ?array $listeners = null;

	/** @var array<string, string> */
	public array $cdn_mappings = [];

	public string $public_url;
	public ?int $upsert_duration;

	protected function onLoad() : void{
		$config = $this->getConfig();
		$rules = $config->get("resource-packs");
		is_array($rules) || throw new RuntimeException("config::resource-packs must be an array");
		foreach($rules as $index => $rule){
			is_string($rule) || throw new RuntimeException("config::resource-packs::{$index} ($rule) must be a string");
		}
		$this->resource_pack_patterns = array_values($rules);

		$public_url = $config->get("public-url");
		filter_var($public_url, FILTER_VALIDATE_URL) || throw new RuntimeException("config::public-url must be a valid URL");
		if(!str_contains($public_url, "{filename}")){
			$public_url = rtrim($public_url, "/") . "/{filename}";
		}
		$this->public_url = $public_url;

		$upsert_duration = $config->get("upsert-duration");
		if($upsert_duration !== null){
			$upsert_duration = strtotime("+{$upsert_duration}", 0);
			$upsert_duration !== false || throw new RuntimeException("config::upsert-duration ({$config->get("upsert-duration")}) must be a valid duration");
		}
		$this->upsert_duration = $upsert_duration;
	}

	protected function onEnable() : void{
		$this->registerServerResourcePacks();
	}

	/**
	 * @param string $pack_id
	 * @param string $pack_filename
	 * @param string $pack_content
	 * @param array<string, string> $custom_metadata
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function register(string $pack_id, string $pack_filename, string $pack_content, array $custom_metadata = []) : Generator{
		$lmtime = yield from $this->s3Put($pack_filename, $pack_content, custom_metadata: $custom_metadata);
		$this->cdn_mappings[$pack_id] = str_replace("{filename}", $pack_filename, $this->public_url);
		$this->cdn_refresh[$pack_id] = [$pack_id, $pack_filename, $pack_content, $lmtime];
		$this->registerListeners();
	}

	/**
	 * @param ResourcePack $pack
	 * @param array<string, string> $custom_metadata
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function registerSimple(ResourcePack $pack, array $custom_metadata = []) : Generator{
		$server = $this->getServer();
		yield from $this->register($pack->getPackId(), "{$pack->getPackId()}-{$pack->getPackVersion()}.mcpack", $pack->getPackChunk(0, $pack->getPackSize()), [
			"Pack-Id" => $pack->getPackId(),
			"Pack-Name" => $pack->getPackName(),
			"Pack-Size" => (string) $pack->getPackSize(),
			"Pack-Sha256" => bin2hex($pack->getSha256()),
			"Pack-Version" => $pack->getPackVersion(),
			"PocketMine-Host" => "{$server->getName()} {$server->getIp()}:{$server->getPort()} (v{$server->getPocketMineVersion()})",
			...$custom_metadata
		]);
	}

	/**
	 * @param ResourcePack $pack
	 * @param array<string, string> $custom_metadata
	 */
	public function registerAndWait(ResourcePack $pack, array $custom_metadata = []) : void{
		Await::g2c($this->registerSimple($pack, $custom_metadata));
	}

	public function unregister(string $pack_id) : void{
		unset($this->cdn_mappings[$pack_id], $this->cdn_refresh[$pack_id]);
		if(count($this->cdn_mappings) === 0){
			$this->unregisterPacketRewriter();
		}
	}

	private function registerServerResourcePacks() : void{
		$packs = [];
		foreach($this->getServer()->getResourcePackManager()->getResourceStack() as $entry){
			if(!($entry instanceof ZippedResourcePack)){
				continue;
			}
			$path = basename($entry->getPath());
			$allowed = false;
			foreach($this->resource_pack_patterns as $pattern){
				if(fnmatch($pattern, $path)){
					$allowed = true;
					break;
				}
			}
			if($allowed){
				$packs[] = $entry;
			}
		}

		$tasks = [];
		foreach($packs as $pack){
			$server = $this->getServer();
			$tasks[] = $this->register($pack->getPackId(), "{$pack->getPackId()}-{$pack->getPackVersion()}.mcpack", $pack->getPackChunk(0, $pack->getPackSize()), [
				"Pack-Id" => $pack->getPackId(),
				"Pack-Name" => $pack->getPackName(),
				"Pack-Size" => (string) $pack->getPackSize(),
				"Pack-Sha256" => bin2hex($pack->getSha256()),
				"Pack-Version" => $pack->getPackVersion(),
				"PocketMine-Host" => "{$server->getName()} {$server->getIp()}:{$server->getPort()} (v{$server->getPocketMineVersion()})"
			]);
		}
		Await::g2c(Await::all($tasks));
	}

	private function registerListeners() : void{
		if($this->listeners !== null){
			return;
		}
		$not_using_cdn = new WeakMap();
		$packet_rewriter = $this->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event) use($not_using_cdn) : void{
			$packets = $event->getPackets();
			foreach($packets as $index => $packet){
				if($packet instanceof ResourcePacksInfoPacket){
					if(($current = current($event->getTargets())) === false || ($info = $current->getPlayerInfo()) === null){
						continue;
					}
					foreach($packet->resourcePackEntries as $entry_index => $entry){
						if(!isset($this->cdn_mappings[$id = $entry->getPackId()->toString()])){
							continue;
						}
						($ev = new ResourcePackServeCdnEvent($this, $id, $info, $this->cdn_mappings[$id]))->call();
						if($ev->isCancelled()){
							$not_using_cdn[$current] ??= [];
							$not_using_cdn[$current][$id] = true;
							continue;
						}
						$packet->resourcePackEntries[$entry_index] = new ResourcePackInfoEntry(
							$entry->getPackId(),
							$entry->getVersion(),
							$entry->getSizeBytes(),
							$entry->getEncryptionKey(),
							$entry->getSubPackName(),
							$entry->getContentId(),
							$entry->hasScripts(),
							$entry->isAddonPack(),
							$entry->isRtxCapable(),
							$ev->cdn_url
						);
						foreach($event->getTargets() as $target){
							$target->onEnterWorld();
						}
					}
				}elseif($packet instanceof ResourcePackChunkDataPacket){
					if(isset($this->resource_pack_cdn_urls[$packet->packId]) && ($current = current($event->getTargets())) !== false && !isset($not_using_cdn[$current][$packet->packId])){
						unset($packets[$index]);
						$event->setPackets($packets);
					}
				}
			}
		}, EventPriority::LOWEST, $this);
		$refresher = $this->upsert_duration !== null ? $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$time = time();
			foreach($this->cdn_refresh as $value){
				if($time - $value[3] >= $this->upsert_duration){
					$this->register($value[0], $value[1], $value[2]);
				}
			}
		}), 20 * 60) : null;
		$this->listeners = [
			static function() use($packet_rewriter) : void{ HandlerListManager::global()->unregisterAll($packet_rewriter); },
			function() use($refresher) : void{ $refresher?->cancel(); }
		];
	}

	private function unregisterPacketRewriter() : void{
		if($this->listeners !== null){
			foreach($this->listeners as $listener){
				$listener();
			}
			$this->listeners = null;
		}
	}

	/**
	 * @return array{string, string, string}
	 */
	private function s3Credentials() : array{
		$config = $this->getConfig();
		$s3_bucket_endpoint = $config->get("s3-bucket-endpoint");
		$s3_access_key_id = $config->get("s3-access-key-id");
		$s3_access_key_secret = $config->get("s3-access-key-secret");
		filter_var($s3_bucket_endpoint, FILTER_VALIDATE_URL) || throw new RuntimeException("config::s3-bucket-endpoint must be a valid URL");
		is_string($s3_access_key_id) || throw new RuntimeException("config::s3-access-key-id must be a string");
		is_string($s3_access_key_secret) || throw new RuntimeException("config::s3-access-key-secret must be a string");
		return [$s3_bucket_endpoint, $s3_access_key_id, $s3_access_key_secret];
	}

	/**
	 * @param string $method
	 * @param string $filename
	 * @param string $body
	 * @param string|null $content_type
	 * @param array<int, mixed> $extra_opts
	 * @param array<string, string> $custom_metadata
	 * @return Generator<mixed, Await::RESOLVE, void, InternetRequestResult>
	 */
	private function s3Request(string $method, string $filename, string $body, ?string $content_type, array $extra_opts, array $custom_metadata = []) : Generator{
		[$endpoint, $access_key, $secret_key] = $this->s3Credentials();

		$host = parse_url($endpoint, PHP_URL_HOST);
		$base_path = rtrim(parse_url($endpoint, PHP_URL_PATH) ?: "", "/");
		$path = str_replace("%2F", "/", rawurlencode($filename));
		$uri = $base_path . "/" . $path;
		$url = rtrim($endpoint, "/") . "/" . $path;

		$date = gmdate("Ymd");
		$amz_date = gmdate("Ymd\THis\Z");
		$hash = hash("sha256", $body);

		$metadata_headers = [];
		foreach($custom_metadata as $key => $value){
			$key = strtolower($key);
			$metadata_headers["x-amz-meta-$key"] = trim($value);
		}
		ksort($metadata_headers);

		$headers = "host:$host\nx-amz-content-sha256:$hash\nx-amz-date:$amz_date\n";
		foreach($metadata_headers as $key => $value){
			$headers .= "$key:$value\n";
		}

		$signed_headers = "host;x-amz-content-sha256;x-amz-date";
		if($metadata_headers !== []){
			$signed_headers .= ";" . implode(";", array_keys($metadata_headers));
		}
		$scope = "$date/auto/s3/aws4_request";
		$canonical_request = "$method\n$uri\n\n$headers\n$signed_headers\n$hash";
		$string_to_sign = "AWS4-HMAC-SHA256\n$amz_date\n$scope\n" . hash("sha256", $canonical_request);

		$hmac = static fn(string $key, string $data): string => hash_hmac("sha256", $data, $key, true);
		$signing_key = $hmac($hmac($hmac($hmac("AWS4$secret_key", $date), "auto"), "s3"), "aws4_request");
		$signature = hash_hmac("sha256", $string_to_sign, $signing_key);

		$request_headers = [
			"Authorization: AWS4-HMAC-SHA256 Credential=$access_key/$scope, SignedHeaders=$signed_headers, Signature=$signature",
			"Content-Type: $content_type",
			"x-amz-date: $amz_date",
			"x-amz-content-sha256: $hash",
		];
		foreach($metadata_headers as $key => $value){
			$request_headers[] = "$key: $value";
		}
		$operation = new BulkCurlTaskOperation($url, extraHeaders: $request_headers, extraOpts: $extra_opts + [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true]);
		return yield from Await::promise(function($resolve, $reject) use($operation) : void{
			$this->getServer()->getAsyncPool()->submitTask(new BulkCurlTask([$operation], static function($responses) use($resolve, $reject) : void{
				if($responses[0] instanceof InternetException){
					$reject($responses[0]);
				}else{
					$resolve($responses[0]);
				}
			}));
		});
	}

	/**
	 * @param string $filename
	 * @param string $body
	 * @param string $content_type
	 * @param int|null $upsert_duration
	 * @param array<string, string> $custom_metadata
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	private function s3Put(string $filename, string $body, string $content_type = "application/zip", ?int $upsert_duration = null, array $custom_metadata = []) : Generator{
		$upsert_duration ??= $this->upsert_duration;
		if($upsert_duration > 0){
			/** @var InternetRequestResult $head */
			$head = yield from $this->s3Request("HEAD", $filename, "", $content_type, [CURLOPT_NOBODY => true]);
			if($head->getCode() === 200){
				foreach($head->getHeaders() as $headers){
					if(isset($headers["last-modified"]) && ($lmtime = strtotime($headers["last-modified"])) > time() - $upsert_duration){
						$this->getLogger()->debug("Skipping upload for {$filename} - last operation executed on: " . date("Y-m-d H:i:s", $lmtime) . " (i.e., < " . ($upsert_duration !== null ? "{$upsert_duration}s" : "forever") . " ago)");
						return $lmtime;
					}
				}
			}elseif($head->getCode() !== 404){
				throw new RuntimeException("Failed to query resource pack from CDN ({$head->getCode()}): {$head->getBody()}");
			}
		}

		$this->getLogger()->debug("Uploading file {$filename} (" . strlen($body) . " bytes)");
		$time = microtime(true);
		/** @var InternetRequestResult $result */
		$result = yield from $this->s3Request("PUT", $filename, $body, $content_type, [CURLOPT_POSTFIELDS => $body], $custom_metadata);
		$result->getCode() === 200 || throw new RuntimeException("Failed to upload resource pack to CDN ({$result->getCode()}): {$result->getBody()}");
		$this->getLogger()->debug(sprintf("Upload complete - {$filename} (took %.2fs)", microtime(true) - $time));
		return (int) floor($time);
	}
}