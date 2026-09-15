package gallery.memories.server

/** Rejects requests lacking the per-process HttpOnly cookie, so other on-device apps cannot use the server. */
class AuthGuard(val secret: String) {
    fun authorized(req: HttpRequest): Boolean {
        val cookie = req.headers["cookie"] ?: return false
        return cookie.split(";").any { it.trim() == "local-auth=$secret" }
    }

    /** Set-Cookie value for the auth cookie. HttpOnly keeps it out of document.cookie. */
    fun cookieHeader(): String = "local-auth=$secret; Path=/; HttpOnly"
}
