class NativeX {

    isNative() {
        return this.postMessageBody("isNative", undefined)
    }

    setThemeColor(color, isDark) {
        this.postMessageBody("setThemeColor", {
            "color": color,
            "isDark": isDark
        })
    }

    playTouchSound() {
        return this.postMessageBody("playTouchSound", undefined)
    }

    toast(message, long) {
        return this.postMessageBody("toast", {
            "message": message,
            "long": long
        })
    }

    logout() {
        return this.postMessageBody("logout", undefined)
    }

    reload() {
        return this.postMessageBody("reload", undefined)
    }

    downloadFromUrl(url, filename) {
        return this.postMessageBody("downloadFromUrl", {
            "url": url,
            "filename": filename
        })
    }

    playVideo(auid, fileid, urlArray) {
        return this.postMessageBody("playVideo", {
            "auid": auid,
            "fileid": fileid,
            "urlArray": urlArray
        })
    }

    destroyVideo(fileid) {
        return this.postMessageBody("destroyVideo", {
            "fileid": fileid
        })
    }

    configSetLocalFolders(json) {
        return this.postMessageBody("configSetLocalFolders", {
            "json": json
        })
    }

    configGetLocalFolders() {
        return this.postMessageBody("configGetLocalFolders", undefined)
    }

    configHasMediaPermission() {
        return this.postMessageBody("configHasMediaPermission", undefined)
    }

    getSyncStatus() {
        return this.postMessageBody("getSyncStatus", undefined)
    }

    setHasRemote(auids, buids, value) {
        return this.postMessageBody("setHasRemote", {
            "auids": auids,
            "buids": buids,
            "value": value
        })
    }

    printLog(message) {
        return this.postMessageBody("printLog", {
            "message": message
        })
    }

    postMessageBody(method, parameter) {
        const body = {
            method: method,
            parameter: parameter
        }
        window.webkit.messageHandlers.nativex.postMessage(body).then((response) => {
            return response
        })
    }
}

globalThis.nativex = new NativeX()

const {fetch: origFetch} = window;
window.fetch = async (...args) => {
    globalThis.nativex.printLog("Fetch request for: " + args);
    return await origFetch(...args);
}