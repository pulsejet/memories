package gallery.memories.data.remote.assets

import android.util.Base64
import android.util.Log
import org.json.JSONObject
import java.math.BigInteger
import java.security.MessageDigest

/**
 * Verifies the Ed25519 (curve25519) signature over the JS asset manifest
 * before any URL or hash from describeApi is trusted or executed.
 * See OtherController::describeApi (jsManifest/jsManifestSig) and the
 * pinned public key in webpack.manifest-sign-plugin.ts.
 */
object ManifestVerifier {
    private val TAG = ManifestVerifier::class.java.simpleName

    /** Prod Ed25519 public key (base64). */
    const val PUBLIC_KEY_B64 = "aA2fpqrrJHTiCkE9ecQNDR5V1qO7oHppjdJQ7Pj5nhQ="

    /**
     * True when the describe carries no manifest, or its manifest signature verifies.
     * A present manifest with a missing or invalid signature is refused.
     */
    fun verifyDescribe(describe: JSONObject): Boolean {
        if (describe.isNull("jsManifest")) return true
        val manifest = fieldBytes(describe, "jsManifest")
        if (manifest == null) {
            Log.e(TAG, "Bad jsManifest encoding")
            return false
        }
        if (describe.isNull("jsManifestSig")) {
            Log.e(TAG, "Manifest without signature")
            return false
        }
        val sigDoc = fieldBytes(describe, "jsManifestSig")
        if (sigDoc == null) {
            Log.e(TAG, "Bad jsManifestSig encoding")
            return false
        }
        val sig = try {
            Base64.decode(JSONObject(sigDoc.toString(Charsets.UTF_8)).getString("curve25519"), Base64.DEFAULT)
        } catch (_: Exception) {
            Log.e(TAG, "Bad jsManifestSig encoding")
            return false
        }
        if (sig.size != 64) {
            Log.e(TAG, "Bad signature length")
            return false
        }
        val valid = try {
            Ed25519.verify(Base64.decode(PUBLIC_KEY_B64, Base64.DEFAULT), manifest, sig)
        } catch (e: Exception) {
            Log.e(TAG, "Verification error", e)
            false
        }
        if (!valid) Log.e(TAG, "Manifest signature mismatch")
        return valid
    }

    private fun fieldBytes(obj: JSONObject, key: String): ByteArray? {
        val raw = obj.optString(key, "")
        if (raw.isEmpty()) return null
        return try {
            Base64.decode(raw, Base64.DEFAULT)
        } catch (_: Exception) {
            null
        }
    }
}

/** Minimal Ed25519 verifier (RFC 8032 section 5.1.7) over platform SHA-512. */
private object Ed25519 {
    private val P = BigInteger.ONE.shiftLeft(255).subtract(BigInteger.valueOf(19))
    private val MASK255 = BigInteger.ONE.shiftLeft(255).subtract(BigInteger.ONE)
    private val L = BigInteger.ONE.shiftLeft(252).add(BigInteger("27742317777372353535851937790883648493"))
    private val D = BigInteger.valueOf(-121665).mod(P)
        .multiply(BigInteger.valueOf(121666).modInverse(P)).mod(P)
    private val TWO_D = D.shiftLeft(1).mod(P)
    private val BASE = decompress(
        "58".plus("66".repeat(31)).chunked(2).map { it.toInt(16).toByte() }.toByteArray(),
    )!!

    private data class Pt(val x: BigInteger, val y: BigInteger, val z: BigInteger, val t: BigInteger)

    fun verify(pub: ByteArray, msg: ByteArray, sig: ByteArray): Boolean {
        if (pub.size != 32 || sig.size != 64) return false
        val rEnc = sig.copyOfRange(0, 32)
        val s = decodeLE(sig.copyOfRange(32, 64))
        if (s >= L) return false
        val r = decompress(rEnc) ?: return false
        val a = decompress(pub) ?: return false
        val h = decodeLE(sha512(rEnc + pub + msg))
        return equal(scalarmult(BASE, s), add(r, scalarmult(a, h)))
    }

    private fun decodeLE(bytes: ByteArray): BigInteger = BigInteger(1, bytes.reversedArray())

    private fun sha512(bytes: ByteArray): ByteArray = MessageDigest.getInstance("SHA-512").digest(bytes)

    private fun decompress(enc: ByteArray): Pt? {
        if (enc.size != 32) return null
        val y = decodeLE(enc).and(MASK255)
        if (y >= P) return null
        val y2 = y.multiply(y).mod(P)
        val x2 = y2.subtract(BigInteger.ONE).mod(P)
            .multiply(y2.multiply(D).add(BigInteger.ONE).mod(P).modInverse(P)).mod(P)
        var x = x2.modPow(P.add(BigInteger.valueOf(5)).divide(BigInteger.valueOf(8)), P)
        if (!x.multiply(x).mod(P).equals(x2)) {
            x = x.multiply(BigInteger.TWO.modPow(P.subtract(BigInteger.ONE).divide(BigInteger.valueOf(4)), P)).mod(P)
        }
        if (!x.multiply(x).mod(P).equals(x2)) return null
        if (x.testBit(0) != ((enc[31].toInt() and 0xFF) shr 7 == 1)) x = P.subtract(x)
        return Pt(x, y, BigInteger.ONE, x.multiply(y).mod(P))
    }

    private fun add(p: Pt, q: Pt): Pt {
        val a = p.y.subtract(p.x).mod(P).multiply(q.y.subtract(q.x).mod(P)).mod(P)
        val b = p.y.add(p.x).mod(P).multiply(q.y.add(q.x).mod(P)).mod(P)
        val c = p.t.multiply(TWO_D).mod(P).multiply(q.t).mod(P)
        val d = p.z.shiftLeft(1).mod(P).multiply(q.z).mod(P)
        val e = b.subtract(a).mod(P)
        val f = d.subtract(c).mod(P)
        val g = d.add(c).mod(P)
        val h = b.add(a).mod(P)
        return Pt(e.multiply(f).mod(P), g.multiply(h).mod(P), f.multiply(g).mod(P), e.multiply(h).mod(P))
    }

    private fun scalarmult(p: Pt, e: BigInteger): Pt {
        var q = Pt(BigInteger.ZERO, BigInteger.ONE, BigInteger.ONE, BigInteger.ZERO)
        for (i in e.bitLength() - 1 downTo 0) {
            q = add(q, q)
            if (e.testBit(i)) q = add(q, p)
        }
        return q
    }

    private fun equal(p: Pt, q: Pt): Boolean =
        p.x.multiply(q.z).mod(P).equals(q.x.multiply(p.z).mod(P)) &&
            p.y.multiply(q.z).mod(P).equals(q.y.multiply(p.z).mod(P))
}
