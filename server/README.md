# server/

Files that live on the server, not in the deployed code. `uploads.htaccess` is copied by hand or by provisioning into the uploads directory (`install -m 644 server/uploads.htaccess <docroot>/wp-content/uploads/.htaccess`); see [docs/05-hardening.md](../docs/05-hardening.md).
