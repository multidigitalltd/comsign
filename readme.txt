=== ComSign ===
Contributors: multidigitalltd
Tags: signature, digital signature, pdf, esignature, hebrew
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.20.0
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

= 0.20.0 =
* New: client portal at /comsign/app (also ?comsign_app=1) - a clean front-end
  workspace for logged-in account users, separate from WP Admin. Includes a
  dashboard (status summary + recent documents), an account-scoped documents
  list, and a read-only document detail with signer progress.
* The portal is fully tenant-scoped and mobile-friendly, with a workspace
  switcher for users who belong to more than one account.

= 0.19.0 =
* New (foundation): multi-tenant accounts/workspaces. Documents and templates
  now belong to an account; the admin document list, edit screen and file
  downloads are scoped so a user only ever sees their own account's documents.
* New: hierarchical sub-accounts - a manager (owner/admin) of a parent account
  automatically sees all descendant accounts' documents (e.g. a company manager
  seeing every agent's documents), while a plain member sees only their own.
* New: account membership with owner/admin/member roles and a workspace switcher
  for users who belong to more than one account.
* Migration is non-breaking: existing documents/templates move to a default
  workspace and current administrators become its owners.

= 0.18.0 =
* New: document timeline on the edit screen - a clear chronological view of
  every event (created, sent, viewed, signed, declined, completed, reminded,
  expired...) with the signer's name and a readable timestamp, plus the current
  status and expiry at a glance.
* New: extend the signing deadline in one click (7 / 14 / 30 days) and download
  the audit trail straight from the timeline. Audit events now show readable
  labels instead of raw keys.

= 0.17.0 =
* New: System Status screen (ComSign → System Status) with at-a-glance checks
  for PHP, GD, OpenSSL, WP-Cron, protected storage, REST key and webhook, plus
  one-click "Send test email" and "Send test webhook" buttons to confirm
  connectivity on the live server.

= 0.16.0 =
* New: dedicated public verification page at /comsign/verify (also reachable via
  ?comsign_verify=1) with a styled authentic / not-found result - no shortcode
  page required. The [comsign_verify] shortcode still works.
* New: every signature certificate now carries a QR code that opens the
  verification page for that document.

= 0.15.0 =
* New: signer-friendly UX. Fields now carry a human label and optional help
  text (set in the editor) so signers no longer see a bare "Your answer".
* New: clearer signing flow - plain-language consent with the legal wording in
  a collapsible "More information" section, a prominent "Sign & finish" button
  with a secondary "Decline", and distinct completion messages for "fully
  signed" vs "waiting for other signers".
* New: mobile-first signing page - touch-friendly controls, the page no longer
  scrolls while drawing a signature, inline per-field validation that scrolls to
  and highlights the first problem.
* Fix: the admin field editor now preserves the "required" flag and the file
  upload field type on save (both were previously dropped).

= 0.14.0 =
* New: signer file upload. Add a "File upload" field so a signer attaches a
  file (e.g. an ID copy) while signing. Uploads are validated (type + 8 MB cap),
  stored privately, listed on the certificate page, and emailed with the signed
  PDF to CC recipients. Removed with the document.

= 0.13.0 =
* New: in-person signing. From a document, hand your device to a present signer
  and open the signing page directly - the session is pre-verified (the admin
  authorises it) and the action is recorded in the audit trail as in-person.

= 0.12.0 =
* New: Analytics dashboard - totals by status, completion rate, average
  time-to-complete, pending signatures, a 14-day completions sparkline, and a
  "needs attention" list of documents stuck out for signature over 7 days.

= 0.11.0 =
* New: CC recipients. Add people who receive the fully signed PDF by email when
  signing completes, without being signers themselves.

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
