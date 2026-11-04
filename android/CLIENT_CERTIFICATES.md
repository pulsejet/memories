# TLS client certificates

Install the client identity (certificate and private key, typically a `.p12` or `.pfx` file) in Android Settings as a **VPN and app certificate**. A CA certificate alone is not a client identity.

On the Memories welcome screen, enter the HTTPS server address, enable **Use a client certificate**, then continue. Select the identity in Android's system picker. Memories selects the certificate before its first request to the server, so a proxy requiring mutual TLS can protect the entire site, including the login endpoints.

The browser used for Nextcloud login manages its own certificate permission and may ask you to select the same identity again. Finish that login and return to Memories as usual.

Server certificate verification stays enabled. Client authentication is independent of the existing **Disable certificate verification** option.

## Storage and scope

Memories saves only the KeyChain alias, hostname and port with the account. The private key stays in Android's credential store. The selection is restored on restart and removed from Memories on logout; logout does not delete the identity from Android.

Only the selected hostname and port may receive the certificate. Redirects to another host or port do not receive it, even if the hosts resolve to the same IP. Use the final HTTPS hostname when signing in. A separate authentication hostname needs to manage its own certificate selection in the browser.

The shared HTTP clients handle API calls, the local proxy, asset downloads, uploads, file downloads and sharing. The WebView and native video player normally access the server through that local proxy, which supplies the client certificate upstream.

To change an expired, removed or inaccessible certificate, install its replacement in Android Settings, sign out of Memories, then sign in again with **Use a client certificate** enabled.

## Verification

The framework-free transport checks need a JDK and the Kotlin command-line tools (`kotlinc` and `kotlin`) on `PATH`:

```sh
bash android/tests/client-certificates.sh
node --test android/tests/welcome.test.mjs
```

The transport checks generate temporary RSA and EC identities, exercise successful and refused mutual TLS connections with TLS 1.2 and TLS 1.3, and verify peer, issuer and key-type restrictions. They do not exercise Android's KeyChain UI or replace device testing.

Before merging, test on an Android device with a server requiring client certificates:

- Sign in from cleared app data, then restart the app and reconnect.
- Cancel the picker, retry, and select a different certificate.
- Browse thumbnails and full-resolution photos; play and seek direct and HLS video.
- Download and share individual photos, videos and multiple files; upload a local photo.
- Sign out and verify a subsequent ordinary login does not use the previous identity.
- Remove the selected identity in Android Settings and verify a new connection fails; install a replacement and sign in again.
- Verify an untrusted server certificate still fails unless verification is explicitly disabled.
- Verify ordinary HTTPS servers still work with the option unchecked.
