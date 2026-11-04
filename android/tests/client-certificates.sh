#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT
for algorithm in RSA EC; do
    keytool -genkeypair -alias "${algorithm,,}" -keyalg "$algorithm" \
        -dname "CN=Test $algorithm" -ext 'SAN=dns:localhost,ip:127.0.0.1' \
        -validity 1 -keystore "$test_dir/identities.p12" -storetype PKCS12 \
        -storepass test-password -keypass test-password -noprompt >/dev/null 2>&1
done
kotlinc \
    app/src/main/java/gallery/memories/data/local/secure/ClientCertificate.kt \
    app/src/main/java/gallery/memories/data/remote/http/ClientCertificateKeyManager.kt \
    tests/ClientCertificateKeyManagerTest.kt \
    -d "$test_dir/checks.jar"
kotlin -classpath "$test_dir/checks.jar" ClientCertificateKeyManagerTestKt "$test_dir/identities.p12"
