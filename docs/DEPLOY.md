# Deploying Tabooki to the VPS (Contabo + Tailscale)

The app is reachable **only over Tailscale**, at `https://tabooki.<your-tailnet>.ts.net`.
Caddy gets a real HTTPS certificate for that name from the host's `tailscaled`, so no
public domain, open ports or Let's Encrypt setup are needed. Images are built on the VPS
itself, so the ARM (Mac) vs x86 (VPS) difference doesn't matter.

> First-deploy checklist. Steps marked ⚠️ haven't been tested end to end yet. Verify them
> on the first deploy and fix this document if anything differs.

## 1. Prepare the VPS (once)

Debian 12/13 or Ubuntu 24.04+.

```sh
# Docker Engine + Compose plugin
curl -fsSL https://get.docker.com | sh

# Tailscale. --ssh enables Tailscale SSH, so port 22 can be closed later
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh --hostname=tabooki
```

In the Tailscale admin console (DNS page): enable **MagicDNS** and **HTTPS Certificates**.
The server is now `tabooki.<your-tailnet>.ts.net`.

⚠️ The production container runs as `www-data` (uid 33), which needs permission to ask
`tailscaled` for certificates:

```sh
echo 'TS_PERMIT_CERT_UID=33' | sudo tee -a /etc/default/tailscaled
sudo systemctl restart tailscaled
```

**Firewall.** Docker publishes port 443 only on the Tailscale IP (see `compose.prod.yaml`),
so nothing is exposed on the public interface. Once you've confirmed Tailscale SSH works
(`ssh root@tabooki` from a machine on the tailnet), you can block public SSH as well, e.g.
with `ufw default deny incoming && ufw allow in on tailscale0 && ufw enable`.

## 2. Configure

```sh
git clone <repo> /opt/tabooki && cd /opt/tabooki
cat > .env <<EOF
SERVER_NAME=tabooki.<your-tailnet>.ts.net
TAILSCALE_IP=$(tailscale ip -4)
APP_SECRET=$(openssl rand -hex 32)
CADDY_MERCURE_JWT_SECRET=$(openssl rand -hex 32)
CADDY_MERCURE_JWT_ISSUER=https://tabooki.<your-tailnet>.ts.net
POSTGRES_PASSWORD=$(openssl rand -hex 24)
AWS_BEARER_TOKEN_BEDROCK=...   # Amazon Bedrock API key (recipe extraction)
AWS_REGION=eu-west-1
# Optional, to change models (defaults in backend/.env):
# BEDROCK_TEXT_MODEL=...
# BEDROCK_VIDEO_MODEL=...
# EXTRACTION_THINKING=0
EOF
chmod 600 .env
```

`.env` is git-ignored. Keep a copy in your password manager.

Check that extraction can reach Bedrock (and which models your key can use) once it's running:
`docker compose -f compose.yaml -f compose.prod.yaml exec php php bin/console app:bedrock:check`.

## 3. Deploy / update

```sh
git pull
make prod-up                               # builds the images, runs migrations, starts app + worker + db
docker compose -f compose.yaml -f compose.prod.yaml exec -it php \
  php bin/console app:user:create you@example.com --name="You" --household="Casa"
```

## 4. Phones (iPhone and Android)

1. Install the **Tailscale** app and sign in to the same tailnet.
   - iOS: in the Tailscale app settings, enable **VPN On Demand** so it connects automatically.
2. Open `https://tabooki.<your-tailnet>.ts.net` and sign in.
3. Install it: **Share → Add to Home Screen** (iOS Safari) or **⋮ → Install app** (Android Chrome).

(From M3: the "Save to Tabooki" Apple Shortcut and the Android share target.)

## 5. Backups (to do before real data goes in)

Nightly database dump plus the media volume (`tabooki_media_data`: cached thumbnails, later
uploads), copied **off the VPS** with restic (Contabo Object Storage, Backblaze B2, or a home
NAS over Tailscale). Sketch:

```sh
docker compose -f compose.yaml -f compose.prod.yaml exec -T database \
  pg_dump -U app -Fc tabooki > /var/backups/tabooki/$(date +%F).dump
restic backup /var/backups/tabooki "$(docker volume inspect -f '{{ .Mountpoint }}' tabooki_media_data)"
```
