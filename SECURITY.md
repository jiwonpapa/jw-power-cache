# Security Policy

## Supported versions

Security fixes are provided for the latest released minor version. Technical Preview builds are not covered by a production SLA, but confirmed vulnerabilities will still be triaged.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's **Report a vulnerability** form in the repository Security tab and include:

- affected version and G7 version;
- reproduction steps or a minimal proof of concept;
- impact, especially whether authenticated or personalized data can enter a cache;
- suggested mitigation, if known.

We aim to acknowledge a report within 3 business days, provide a triage result within 7 business days, and coordinate disclosure after a fix is available. Never include production credentials or personal data.

## Security invariants

Authenticated page and category requests, unknown public cookies, unknown query parameters, unapproved middleware stacks, sensitive non-authentication tokens, and unsafe response headers are always bypassed. Board-list Bearer requests run after `optional.sanctum`: resolved users are keyed by authenticated user ID, while unresolved credentials use the public key and public read permission. Safe public board GET requests may carry only the standard G7 session/XSRF browser context. Missing or malformed control-plane state blocks HIT delivery and rebuilds the control plane with a new runtime epoch.
