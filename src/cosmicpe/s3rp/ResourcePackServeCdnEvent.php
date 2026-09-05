<?php

declare(strict_types=1);

namespace cosmicpe\s3rp;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\plugin\PluginEvent;
use pocketmine\player\PlayerInfo;

/**
 * Called when a resource pack is being served from CDN. All non-readonly properties may be modified to change outcome
 * of event. Cancel the event to fall back and self-serve.
 */
final class ResourcePackServeCdnEvent extends PluginEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Loader $plugin,
		readonly public string $pack_id,
		readonly public PlayerInfo $player,
		public string $cdn_url
	){
		parent::__construct($plugin);
	}
}