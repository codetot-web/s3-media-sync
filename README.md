# S3 Media Sync

WordPress plugin to sync your media library to any S3-compatible storage — AWS S3, iDrive E2, MinIO, Cloudflare R2, DigitalOcean Spaces, and more.

## Features

- **Auto-upload** — files are pushed to S3 immediately when added to the Media Library
- **All image sizes** — original + every resized variant are uploaded automatically
- **Manual bulk sync** — batch sync all existing media from the Tools page (browser-based, resumable)
- **CDN URL rewriting** — serve media from a custom public URL / CDN instead of local server
- **Local file cleanup** — optionally delete local copies after successful S3 upload
- **Delete sync** — removes S3 objects when media is deleted from WordPress
- **Test connection** — verify credentials and bucket access before enabling

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Composer (to install the AWS SDK)

## Installation

### From source (GitHub)

```bash
cd wp-content/plugins/
git clone https://github.com/codetot-huong/s3-media-sync.git
cd s3-media-sync
composer install --no-dev --optimize-autoloader
```

Activate the plugin in **Plugins** → **Installed Plugins**.

### Configuration

Go to **Settings → S3 Media Sync** and fill in:

| Field | Description |
|-------|-------------|
| Access Key | S3 access key ID |
| Secret Key | S3 secret access key |
| Bucket | Bucket name |
| Region | AWS region (e.g. `ap-southeast-1`). Use `us-east-1` for most S3-compatible providers |
| Endpoint | S3-compatible endpoint hostname or URL (leave blank for AWS) |
| Public Bucket URL | Optional CDN / public URL base (e.g. `https://cdn.example.com`) |
| Delete local after upload | Remove local file once S3 upload succeeds |
| Disable SSL Verify | Skip SSL certificate check (for self-signed certs) |

Click **Test Connection** to verify, then enable sync and save.

### Manual Sync

Go to **Tools → S3 Media Sync** and click **Start Manual Sync to S3**. Progress is shown in-page and saved to `localStorage` so you can stop and resume at any time.

## S3-Compatible Providers

Tested with:
- AWS S3
- iDrive E2
- MinIO (self-hosted)
- Cloudflare R2
- DigitalOcean Spaces

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
