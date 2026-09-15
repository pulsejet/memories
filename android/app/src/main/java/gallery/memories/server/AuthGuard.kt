package gallery.memories.server

import io.ktor.server.application.ApplicationCall

/** Rejects requests lacking the per-process HttpOnly cookie, so other on-device apps cannot use the server. */
class AuthGuard(val secret: String) {
    fun authorized(cookieHeader: String?): Boolean {
        if (cookieHeader == null) return false
        return cookieHeader.split(";").any { it.trim() == "local-auth=$secret" }
    }

    fun authorized(call: ApplicationCall): Boolean = authorized(call.request.headers["Cookie"])

    /** Set-Cookie value for the auth cookie. HttpOnly keeps it out of document.cookie. */
    fun cookieHeader(): String = "local-auth=$secret; Path=/; HttpOnly"
}
