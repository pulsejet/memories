package gallery.memories.server

import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.ApplicationCall
import io.ktor.server.response.respondText

/** Shared Ktor response helpers for the local server. */
object KtorRespond {
    /** Plain-text response with an explicit status. */
    suspend fun plain(call: ApplicationCall, code: HttpStatusCode, body: String) {
        call.respondText(body, ContentType.Text.Plain, code)
    }

    /** Standard status when known, otherwise a bare custom code like the old reason() fallback. */
    fun status(code: Int): HttpStatusCode =
        try {
            HttpStatusCode.fromValue(code)
        } catch (_: Exception) {
            HttpStatusCode(code, "Status")
        }

    /** Parses a Content-Type value, falling back instead of throwing on upstream garbage. */
    fun contentType(value: String?, fallback: ContentType = ContentType.Application.OctetStream): ContentType =
        try {
            if (value == null) fallback else ContentType.parse(value)
        } catch (_: Exception) {
            fallback
        }
}
