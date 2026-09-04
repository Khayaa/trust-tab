---
paths:
  - 'app/Auth/**'
---

# Auth

## Phone OTP login never enumerates accounts
Customers sign in with the MoMo number the merchant typed. SendLoginOtp always looks like it sent a code, but only hashes one when the number is known, so the form cannot be used to discover who has a tab. The hash lives in cache for five minutes; the plaintext is never stored. VerifyLoginOtp returns the same message for a wrong code, an expired code, and an unknown number.

trusttab.otp.reveal (TRUSTTAB_OTP_REVEAL) is the only thing that writes the plaintext back to the login page, for the laptop and the stage. phpunit.xml pins it false. Do not log the code. A real SMS sender replaces LoginOtp::reveal(), not the hash store.

Send is limited to three codes per number and IP per ten minutes; verify is limited to five tries a minute. A used code is forgotten.

## Reveal OTP only when it was hashed
Reveal the plaintext OTP only when a hash was stored for that MSISDN. Do not print a dummy code for unknown numbers.
