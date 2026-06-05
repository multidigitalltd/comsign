=== ComSign ===
Contributors: multidigitalltd
Tags: signature, digital signature, pdf, esignature, hebrew
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure digital document signing for WordPress with full Hebrew/RTL support.
Upload a PDF, place signature fields, send for signing by email, capture the
signature online and keep a complete audit trail.

== Description ==

ComSign brings a DocuSign-style electronic signing flow to WordPress, built
with security and Hebrew/RTL as first-class concerns.

Workflow:

1. Upload a PDF in the admin.
2. Add signers (name + email) and place signature/date fields per signer using
   an in-browser PDF editor.
3. Send signing invitations by email — each signer receives a personal,
   single-use, hashed token link (no WordPress account required).
4. The signer reviews the document and signs by drawing, typing or stamping.
5. ComSign embeds the signature into the PDF, records a full audit trail
   (IP, browser, timestamp, consent) and appends a signature certificate page.
6. Download, store and verify the signed PDF (SHA-256).

= Signature level =

ComSign supports **electronic signatures** (DocuSign-style: draw / type / stamp,
embedded into the PDF) and, when you upload a PKCS#12 certificate, real
**PKI / PAdES** cryptographic signatures (PKCS#7, eIDAS / ComSign compatible).
Both run behind a clean `SignatureProviderInterface`; the cryptographic provider
is selected automatically once a certificate is configured.

= Signer authentication =

Every signature is backed by an **audit trail** (IP, User-Agent, timestamp,
recorded consent). On top of that you can require, per signer, an **access code**
(shared out of band) or an **emailed one-time code (OTP)** before the document
can be viewed or signed.

= Automation =

A REST API (`comsign/v1`) lists/reads documents, reads the audit trail and
creates + sends documents (API key or logged-in manager). Outgoing **webhooks**
POST a signed JSON payload (HMAC-SHA256) for every event.

= Security =

* Per-signer tokens are random (256-bit) and stored only as SHA-256 hashes.
* Source and signed PDFs live in a protected uploads directory and are only
  ever streamed through authenticated/tokenised endpoints.
* All admin actions are guarded by capabilities + nonces.
* All output is escaped; all database access uses prepared statements.
* A PKI certificate's password is stored AES-256 encrypted with a key derived
  from your wp-config.php auth salt (not the database), in a non-autoloaded
  option; the certificate file itself has an unguessable name.
* Outgoing webhooks are restricted to http(s) and refuse private/loopback/
  link-local/reserved targets (SSRF protection).

= Server hardening (important on nginx) =

The protected uploads directory ships with `.htaccess` (Apache) and `web.config`
(IIS) deny rules. On **nginx** these are ignored, so add a location block to
your server config to block direct access to the storage directory, e.g.:

`location ~* /wp-content/uploads/comsign/ { deny all; return 403; }`

This keeps source/signed PDFs and the PKI certificate reachable only through
ComSign's authenticated endpoints.

== Third-party libraries ==

* setasign/FPDI + TCPDF (PDF import & stamping) — bundled in `vendor/`.
* signature_pad (MIT) and pdf.js (Apache-2.0) — bundled in `assets/vendor/`.

== Installation ==

1. Upload the `comsign` folder to `/wp-content/plugins/`.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Open the “ComSign” menu to upload your first document.

== Frequently Asked Questions ==

= Which PDFs are supported? =

PDFs up to version 1.4 are read by the bundled (free) FPDI parser. Newer or
encrypted PDFs may need to be re-saved/normalised first; ComSign surfaces a
clear error in that case.

= Does it work in Hebrew? =

Yes. The UI is fully translatable (a Hebrew translation ships in `languages/`)
and renders right-to-left automatically on RTL locales.

= How can someone verify a signed document? =

Add the `[comsign_verify]` shortcode to any page. A verifier enters the document
ID and its SHA-256 code (shown on the document screen and the signature
certificate) to confirm the document is authentic and see who signed it.

== Changelog ==

= 0.10.0 =
* New: Branding. Set a brand name, logo and accent colour (Settings) shown to
  signers on the signing/verification pages; the brand name is also used in
  invitation emails.

= 0.9.1 =
* Security: PKI certificate password is now AES-256 encrypted (key from the
  wp-config.php auth salt, not the DB) and kept in a non-autoloaded option;
  the certificate file uses an unguessable name.
* Security: webhook delivery is restricted to http(s) and blocks private/
  loopback/link-local/reserved targets (SSRF protection).
* Security: certificate upload validates name/size/extension before reading;
  storage dir now also ships web.config (IIS) + nginx guidance.
* Fix: document duplication now copies field options and the required flag.
* Fix: creating a document from a template requires recipients (no more empty
  drafts) and a recipient for every role that has fields.
* Fix: REST create-with-send returns 502 + a structured error on send failure
  instead of a misleading 201.
* Fix: signing page now scopes fields to the document (consistent with signing).
* Fix: removed a stray non-ASCII glyph from the public verification result.

= 0.9.0 =
* New: required (smart) fields. Mark signer-filled fields as required in the
  editor (toolbar toggle or click a field's label); the signer cannot complete
  the document until every required field is filled, enforced both in the
  browser and on the server. Required flags are preserved in templates.

= 0.8.0 =
* New: signer identity verification. Per signer, require an access code (shared
  by the sender out of band) or an emailed one-time code (OTP) before the
  document can be viewed or signed. No third-party service required.

= 0.7.0 =
* New: real PKI / PAdES signing. Upload a PKCS#12 (.p12/.pfx) certificate in
  Settings and completed documents are signed with a cryptographic
  PKCS#7/PAdES signature (eIDAS / ComSign compatible). Without a certificate,
  documents continue to use electronic signatures.

= 0.6.1 =
* New: outgoing webhooks - every event is POSTed as signed JSON (HMAC-SHA256)
  to a configurable URL.
* New: REST API (comsign/v1) to list/read documents, read the audit trail, and
  create + send documents, authenticated by an API key or a logged-in manager.

= 0.6.0 =
* New: reusable Templates - save a document (source + fields, keyed by role) as
  a template, then create new documents from it by assigning recipients to roles.
* New: Bulk send - from a single-role template, paste a recipient list to create
  and send an individual document to each.

= 0.5.0 =
* New field types: number, checkbox, and choice (dropdown with admin-defined
  options) that the signer fills in.
* New auto fields (per-signer variables): Name and Email auto-fill from the
  signer's details when they sign (joining the existing auto Date field).
* Editor: a field-type dropdown replaces the per-type buttons; choice fields
  prompt for their options.

= 0.4.0 =
* New: sequential signing — require signers to sign in order; the next signer
  is invited automatically once the previous one signs.
* New: automatic reminders (daily WP-Cron) for signers who have not signed,
  configurable on the new Settings screen.
* New: link expiry — set how many days a signing link stays valid.
* New: custom message to signers, included in the invitation email.
* New: downloadable audit-trail report (PDF) per document.

= 0.3.0 =
* New: link-only signers — email is now optional; reach a signer purely via a
  shared link / WhatsApp.
* New: merge variables in composed documents — {{name}} placeholders plus
  automatic {{date}} and {{site}}.
* New: re-send the invitation to a single signer.
* New: resize and remove signature/field boxes directly in the editor.
* New: public document verification via the [comsign_verify] shortcode
  (document ID + SHA-256).

= 0.2.0 =
* New: compose a document from rich text (wp_editor) and generate its PDF —
  in addition to uploading a PDF.
* New: fillable text fields the signer completes themselves, stamped into the PDF.
* New: per-signer "Get signing link" with copy-to-clipboard and WhatsApp share
  (optional phone number per signer).
* New: signer management (remove signer), document duplication, and a dashboard
  status summary.

= 0.1.0 =
* Initial release: upload, field placement, email invitations, online signing
  (draw/type/stamp), PDF embedding, audit trail, signature certificate,
  download & SHA-256 verification. Full Hebrew/RTL support.
