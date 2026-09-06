# S3RP
Put PocketMine resource packs on an S3 bucket. Players pull them from the public URL instead of your server.

PocketMine serves resource packs from the gameserver. On join it streams the zip in chunks.
S3RP uploads those packs to some S3 bucket instead of using your server's bandwidth.

You'll notice download speed gains when packs are large, or new joins are many, or you frequently update your packs, or some amalgamation of these factors.
This is most likely an overkill if you have a tiny pack and a quiet server.

## Setup S3RP using Cloudflare R2
[Cloudflare R2](https://www.cloudflare.com/products/r2) provides generous free limits for our use case. Follow the steps below to setup:
1. From your [Cloudflare dashboard](https://dash.cloudflare.com/), visit **R2 Object Storage** (under **Storage & databases**)
2. Click **Create Bucket**. Give your bucket a name, then click **Create bucket**.
3. Select your bucket. In your bucket's **Settings**, copy your **S3 API**.
   - Set this as your `s3-bucket-endpoint` in plugin `config.yml`
4. Enable **Public Development URL** and copy the value.
	- Set this as your `public-url` in plugin `config.yml`
    - Alternatively, you can configure **Custom Domains** which is what Cloudflare recommends for production usage.
5. Go back to **R2 Object Storage** page, select **Manage API Tokens**
6. Select **Create User API token**.
    - Set permission to **Object Read & Write** - S3RP auto-uploads packs to S3 so we need write permission.
    - Under **Specify bucket(s)**, select **Apply to specific buckets only** and select the bucket you just created.
7. Click **Create User API Token**.
8. Copy your **Access Key ID**.
	- Set this as your `s3-access-key-id` in plugin `config.yml`
9. Copy your **Secret Access Key**.
	- Set this as your `s3-access-key-secret` in plugin `config.yml`

Done. Restart your server. S3RP will upload your server resource packs to Cloudflare R2 to serve your players.

## Q: I want to serve only some packs from the CDN
Edit `resource-packs` in your `config.yml`. For example, if your server's resource_packs/resource_packs.yml looks like:
```yaml
resource_stack:
 - Deadpool.zip
 - CapeResourcePack.zip
 - BetterVanilla.mcpack
```
And you want to host only BetterVanilla.mcpack, you would use this configuration:
```yaml
resource-packs:
  - BetterVanilla.mcpack
```

## Q: I build my resource packs during runtime
Register them with PocketMine first, then with S3RP. Use `registerAndWait()` / `unregister()` APIs to serve your resouce pack from CDN.
```php
// first, register your pack to server's manager
$manager = $server->getResourcePackManager();
$stack = $manager->getResourceStack();
$stack[] = $my_resource_pack;
$manager->setResourceStack($stack);

// then let S3RP serve your pack from CDN
/** @var \cosmicpe\s3rp\Loader $plugin */
$plugin = $server->getPluginManager()->getPlugin("S3RP");
$plugin->registerAndWait($my_resource_pack);
```

## Q: I want to keep the old download path for some players
Cloudflare services are heavily throttled in Russia and China. You can cancel `ResourcePackServeCdnEvent` event to serve certain players the old fashion way (i.e., using your pocketmine server's bandwidth).
```php
public function handleResourcePackServeCdn(ResourcePackServeCdnEvent $event) : void{
	if($event->player->getUsername() === "Steve"){
		$event->cancel();
	}
}
```

## Q: My resource pack is already on a public URL
S3RP uploads. If you do not need that, you may want to go with something simpler like [presentkim-pm/ResourcePackCdn](https://github.com/presentkim-pm/ResourcePackCdn).
S3RP is not ideal if you already serve resource packs with nginx or any static host.
