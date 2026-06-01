# CourtFlow AI — Closed Beta Launch Checklist

## Pre-launch

- [ ] Legal review of disclaimer text (Settings → Disclaimer)
- [ ] OpenAI API key configured (Settings or `COURTFLOW_OPENAI_API_KEY` in wp-config.php)
- [ ] Upload official blank NY court PDFs to `data/forms/` (UD-2.pdf, UD-3.pdf, UCS-111.pdf)
- [ ] Install `pdftk` on server (`apt install pdftk` or brew install pdftk-java)
- [ ] Create Workspace page with CourtFlow Workspace block + template
- [ ] Run `wp courtflow migrate` and `wp courtflow seed`
- [ ] Enable 2FA for all `cf_admin_*` users (WP 2FA plugin recommended)
- [ ] Configure Cloudflare/WAF in production

## Beta cohort (5–10 pro se litigants)

1. Create subscriber accounts with `cf_intake` capability
2. Provide onboarding: "Describe county, children, contested status"
3. Instrument funnel via Dashboard → Sessions + AI Audit Log
4. Track: session start → facts complete → validation pass → package generated

## Go / No-Go criteria

| Metric | Target |
|--------|--------|
| Session completion rate | ≥ 40% |
| Validation error rate (blocking) | < 30% at first package attempt |
| AI cost per session | < $2.00 |
| PDF generation success | ≥ 80% (requires pdftk + templates) |

## Feedback collection

- Post-session survey link
- Review AI Audit Log for malformed extractions
- Paralegal review of 3 sample generated packages

## Post-beta

- Iterate rules in wp-admin → Procedural Rules
- Add contested-with-children workflow nodes
- Phase 2: Ollama provider, e-filing integration
