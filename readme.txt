=== ComSign ===
Contributors: multidigitalltd
Tags: signature, digital signature, pdf, esignature, hebrew
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.0
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

The current release implements **electronic signatures** (DocuSign-style:
draw / type / stamp, embedded into the PDF). The architecture exposes a clean
`SignatureProviderInterface` extension point so a future **PKI / PAdES**
provider (certificates, cryptographic signatures, ComSign / eIDAS) can be
added without touching the workflow code.

= Signer authentication =

This phase relies on an **audit trail** (IP, User-Agent, timestamp, recorded
consent). OTP (SMS/email) and ID verification are intentionally out of scope
for now, but the data model leaves room for them.

= Security =

* Per-signer tokens are random (256-bit) and stored only as SHA-256 hashes.
* Source and signed PDFs live in a protected uploads directory and are only
  ever streamed through authenticated/tokenised endpoints.
* All admin actions are guarded by capabilities + nonces.
* All output is escaped; all database access uses prepared statements.

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
