# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-14

### Added
- Card payments: wallets (standard, affiliate, sub-affiliate, mass payout), hosted and
  provider-specific payment links with white-label branding, provider list, USD conversion,
  payment status.
- Crypto payments: coin list, coin info and prices, network fees, conversion, wallets with
  pending notices and confirmation count, affiliate and mass-payout wallets, QR codes, hosted
  multi-coin page, payment status, swaps.
- Callback verification against PayGate.to's RSA signature, with the published `v1` key bundled
  and a resolver for future keys.
- `CallbackData` for the payment parameters, including exact per-address payouts.
- `CallbackToken` to tie each callback to an order with an HMAC (compatible with the Node.js SDK).
- Examples: card, on-site crypto and hosted checkouts, and a callback handler that checks the
  signature, the order token, repeats, the coin and the amount.
- `bin/paygate-callback-tester`: sends signed test callbacks (with your own test key) to a running
  site, including unsigned, tampered, pending and simultaneous deliveries.
