package gallery.memories.mapper

import org.json.JSONObject

class Response {
    companion object {
        val OK get(): JSONObject {
            return JSONObject().put("message", "ok")
        }
    }
}