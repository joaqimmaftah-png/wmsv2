<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Host-local overrides for this installation (loaded at the end of config.php).
| Never commit this file.
*/

// Secret key (>= 32 chars). Taken from config.local.php.example.
$config['encryption_key'] = 'a21ed87f4abf521de31a179ea2e648d747406fe7f2de045c496026374fe73136';

// cookie_secure is intentionally NOT set here. config.php turns it on
// automatically whenever the request arrives over HTTPS. Forcing it to TRUE
// makes browsers drop the session + CSRF cookies on an http:// site, so every
// login POST fails with "403 The action you requested is not allowed".

// Keep existing logins working. 15+ accounts still store MD5 hashes
// (e.g. every *@aml.cd seeded user); with FALSE those users cannot sign in.
// Each legacy hash is upgraded to bcrypt automatically on that user's next
// successful sign-in. Set to FALSE only once no "legacy MD5" hashes remain
// in the users table.
$config['legacy_password_login'] = TRUE;
