' HTTP helpers for Memories public + Basic-auth APIs.

function ApiBase(cfg as object) as string
    return cfg.origin + "/apps/memories/api"
end function

function ApiAuthQuery(cfg as object) as string
    if cfg.authMode <> "share" then return ""
    if cfg.shareKind = "album"
        return "token=" + EncodeUri(cfg.token) + "&albums=" + EncodeUri(cfg.token)
    end if
    return "token=" + EncodeUri(cfg.token)
end function

function ApiAppendQuery(url as string, extra as string) as string
    if extra = "" then return url
    if Instr(1, url, "?") > 0
        return url + "&" + extra
    end if
    return url + "?" + extra
end function

function ApiUrl(cfg as object, path as string, query as string) as string
    url = ApiBase(cfg) + path
    url = ApiAppendQuery(url, query)
    url = ApiAppendQuery(url, ApiAuthQuery(cfg))
    return url
end function

function ApiHttpGet(cfg as object, path as string, query as string) as object
    return ApiHttpRequest(cfg, "GET", path, query, "")
end function

function ApiHttpRequest(cfg as object, method as string, path as string, query as string, body as string) as object
    out = { ok: false, code: 0, error: "", json: invalid, text: "" }
    url = ApiUrl(cfg, path, query)
    xfer = CreateObject("roUrlTransfer")
    port = CreateObject("roMessagePort")
    xfer.SetMessagePort(port)
    xfer.SetUrl(url)
    xfer.RetainBodyOnError(true)
    xfer.EnableEncodings(true)
    xfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    xfer.InitClientCertificates()
    xfer.EnablePeerVerification(false)
    xfer.AddHeader("Accept", "application/json")
    xfer.AddHeader("OCS-APIREQUEST", "true")
    xfer.AddHeader("User-Agent", "MemoriesRoku/1.3")

    if cfg.authMode = "account"
        xfer.SetUserAndPassword(cfg.username, cfg.password)
        ba = CreateObject("roByteArray")
        ba.FromAsciiString(cfg.username + ":" + cfg.password)
        xfer.AddHeader("Authorization", "Basic " + ba.ToBase64String())
    end if

    started = false
    if UCase(method) = "GET"
        started = xfer.AsyncGetToString()
    else
        xfer.SetRequest(method)
        started = xfer.AsyncPostFromString(body)
    end if

    if not started
        out.error = "Could not start HTTP request"
        return out
    end if

    timeout = 30000
    elapsed = 0
    while elapsed < timeout
        msg = wait(1000, port)
        elapsed = elapsed + 1000
        if type(msg) = "roUrlEvent"
            code = msg.GetResponseCode()
            out.code = code
            out.text = msg.GetString()
            if code >= 200 and code < 300
                out.ok = true
                if out.text <> ""
                    out.json = ParseJson(out.text)
                end if
            else if code = 401 or code = 403
                out.error = "Share or login not allowed (" + code.ToStr() + ")"
            else if code <= 0
                out.error = "Network error: " + msg.GetFailureReason()
            else
                out.error = "HTTP " + code.ToStr()
            end if
            return out
        end if
    end while

    out.error = "Request timed out"
    return out
end function

function ApiDescribe(cfg as object) as object
    out = { ok: false, code: 0, error: "", json: invalid, text: "" }
    url = cfg.origin + "/apps/memories/api/describe"
    xfer = CreateObject("roUrlTransfer")
    port = CreateObject("roMessagePort")
    xfer.SetMessagePort(port)
    xfer.SetUrl(url)
    xfer.RetainBodyOnError(true)
    xfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    xfer.InitClientCertificates()
    xfer.AddHeader("Accept", "application/json")
    xfer.AddHeader("User-Agent", "MemoriesRoku/1.3")
    if not xfer.AsyncGetToString()
        out.error = "Could not reach server"
        return out
    end if
    elapsed = 0
    while elapsed < 20000
        msg = wait(1000, port)
        elapsed = elapsed + 1000
        if type(msg) = "roUrlEvent"
            code = msg.GetResponseCode()
            out.code = code
            out.text = msg.GetString()
            if code >= 200 and code < 300
                out.ok = true
                out.json = ParseJson(out.text)
            else if code <= 0
                out.error = "Network error: " + msg.GetFailureReason()
            else
                out.error = "Memories not found at this server (HTTP " + code.ToStr() + ")"
            end if
            return out
        end if
    end while
    out.error = "Request timed out"
    return out
end function

function ApiValidateConfig(cfg as object) as object
    result = { ok: false, error: "" }
    desc = ApiDescribe(cfg)
    if not desc.ok
        result.error = desc.error
        return result
    end if

    days = ApiFetchDays(cfg, false)
    if not days.ok
        result.error = days.error
        if days.code = 404
            result.error = "Invalid or expired share"
        end if
        return result
    end if

    result.ok = true
    return result
end function

function ApiFetchDays(cfg as object, reverse as boolean) as object
    q = "nopreload=1"
    if reverse then q = q + "&reverse=1"
    return ApiHttpGet(cfg, "/days", q)
end function

function ApiFetchDayDetail(cfg as object, dayIds as string, reverse as boolean) as object
    q = ""
    if reverse then q = "reverse=1"
    return ApiHttpGet(cfg, "/days/" + dayIds, q)
end function

' ~1080p preview. Prefer JPEG via Accept; NC may still return png/webp.
function ApiPreviewUrl(cfg as object, fileid as dynamic) as string
    q = "x=1920&y=1080&a=1&mode=fill"
    return ApiUrl(cfg, "/image/preview/" + ValidStr(fileid), q)
end function

function ApiStreamUrl(cfg as object, fileid as dynamic) as string
    return ApiUrl(cfg, "/stream/" + ValidStr(fileid), "")
end function

function ApiHostOf(cfg as object) as string
    if cfg = invalid or cfg.origin = invalid or cfg.origin = "" then return "?"
    o = cfg.origin
    ' strip scheme
    i = Instr(1, o, "://")
    if i > 0 then o = Mid(o, i + 3)
    slash = Instr(1, o, "/")
    if slash > 0 then o = Left(o, slash - 1)
    return o
end function

function ApiMimetypeIsRokuNative(mimetype as dynamic) as boolean
    mt = LCase(ValidStr(mimetype))
    if mt = "" then return false
    return mt = "image/jpeg" or mt = "image/jpg" or mt = "image/png" or mt = "image/gif" or mt = "image/webp"
end function

' Sniff first bytes. Returns jpg|png|gif|webp|avif|heic|unknown.
function ApiSniffImageKind(path as string) as string
    ba = CreateObject("roByteArray")
    ' Full read is fine for 1080p previews; partial ReadFile isn't on all firmwares
    if not ba.ReadFile(path) then return "unknown"
    if ba.Count() < 12 then return "unknown"

    if ba[0] = &hFF and ba[1] = &hD8 and ba[2] = &hFF then return "jpg"
    if ba[0] = &h89 and ba[1] = &h50 and ba[2] = &h4E and ba[3] = &h47 then return "png"
    if ba[0] = &h47 and ba[1] = &h49 and ba[2] = &h46 then return "gif"

    ' RIFF....WEBP
    if ba[0] = &h52 and ba[1] = &h49 and ba[2] = &h46 and ba[3] = &h46
        if ba[8] = &h57 and ba[9] = &h45 and ba[10] = &h42 and ba[11] = &h50
            return "webp"
        end if
    end if

    ' ISO BMFF: ....ftyp....
    if ba.Count() >= 12 and ba[4] = &h66 and ba[5] = &h74 and ba[6] = &h79 and ba[7] = &h70
        brand = ""
        for i = 8 to 11
            brand = brand + Chr(ba[i])
        end for
        b = LCase(brand)
        if b = "avif" or b = "avis" then return "avif"
        if b = "heic" or b = "heif" or b = "mif1" or b = "msf1" then return "heic"
    end if

    return "unknown"
end function

function ApiExtForKind(kind as string) as string
    if kind = "jpg" then return "jpg"
    if kind = "png" then return "png"
    if kind = "gif" then return "gif"
    if kind = "webp" then return "webp"
    return ""
end function

' Ensure file extension matches bytes. Reject formats Poster cannot decode.
' Note: roFileSystem has no GetInfo — size via ReadFile (TASK/MAIN only).
function ApiNormalizeDownloadedImage(path as string) as object
    out = { ok: false, path: "", error: "", kind: "" }
    fs = CreateObject("roFileSystem")
    if path = "" or fs = invalid or not fs.Exists(path)
        out.error = "missing file"
        return out
    end if

    ba = CreateObject("roByteArray")
    if not ba.ReadFile(path) or ba.Count() < 64
        out.error = "too small"
        if fs.Exists(path) then fs.Delete(path)
        return out
    end if

    kind = ApiSniffImageKind(path)
    out.kind = kind
    ext = ApiExtForKind(kind)
    if ext = ""
        out.error = "bad fmt " + kind
        fs.Delete(path)
        return out
    end if

    ' Rewrite path with correct extension when needed
    finalPath = path
    dot = 0
    i = Len(path)
    while i > 0
        ch = Mid(path, i, 1)
        if ch = "."
            dot = i
            exit while
        else if ch = "/"
            exit while
        end if
        i = i - 1
    end while
    if dot > 0
        finalPath = Left(path, dot) + ext
    else
        finalPath = path + "." + ext
    end if

    if finalPath <> path
        if fs.Exists(finalPath) then fs.Delete(finalPath)
        if not fs.Rename(path, finalPath)
            ' Rename failed — copy via byte array
            ba = CreateObject("roByteArray")
            if ba.ReadFile(path)
                ba.WriteFile(finalPath)
                fs.Delete(path)
            else
                out.error = "rename failed"
                return out
            end if
        end if
    end if

    out.ok = true
    out.path = finalPath
    return out
end function

function ApiDownloadPreview(cfg as object, fileid as dynamic, path as string, mimetype as dynamic) as object
    ' mimetype reserved for future Accept tuning; always sniff bytes today
    if mimetype = invalid then mimetype = ""
    url = ApiPreviewUrl(cfg, fileid)
    out = ApiDownloadImageUrl(cfg, url, path)
    ' https://IP + bad cert/TLS: peer verify already off; one plain-HTTP retry
    if not out.ok and out.code <= 0
        httpUrl = ApiHttpsIpToHttp(url)
        if httpUrl <> ""
            print "MEMORIES dl http fallback"
            out = ApiDownloadImageUrl(cfg, httpUrl, path)
        end if
    end if
    return out
end function

function ApiHttpsIpToHttp(url as string) as string
    if Left(LCase(url), 8) <> "https://" then return ""
    rest = Mid(url, 9)
    hostport = rest
    slash = Instr(1, rest, "/")
    if slash > 0 then hostport = Left(rest, slash - 1)
    host = hostport
    colon = Instr(1, hostport, ":")
    if colon > 0 then host = Left(hostport, colon - 1)
    if not ApiLooksLikeIpv4(host) then return ""
    return "http://" + rest
end function

function ApiLooksLikeIpv4(host as string) as boolean
    if host = "" then return false
    dots = 0
    for i = 1 to Len(host)
        ch = Mid(host, i, 1)
        if ch = "."
            dots = dots + 1
        else if Asc(ch) < Asc("0") or Asc(ch) > Asc("9")
            return false
        end if
    end for
    return dots = 3
end function

' Prefer async+Cancel (interruptible). Fall back to sync GetToFile if async won't start.
function ApiDownloadImageUrl(cfg as object, url as string, path as string) as object
    out = { ok: false, code: 0, error: "", path: "", kind: "", contentType: "", reason: "" }
    auth = "?"
    if cfg <> invalid and cfg.authMode <> invalid then auth = cfg.authMode
    print "MEMORIES dl start host="; ApiHostOf(cfg); " auth="; auth
    print "MEMORIES dl url="; url
    print "MEMORIES dl path="; path

    fs = CreateObject("roFileSystem")
    if fs = invalid
        out.error = "no fs"
        return out
    end if
    if fs.Exists(path) then fs.Delete(path)

    xfer = CreateObject("roUrlTransfer")
    if xfer = invalid
        out.error = "no xfer"
        return out
    end if
    port = CreateObject("roMessagePort")
    xfer.SetMessagePort(port)
    xfer.SetUrl(url)
    xfer.RetainBodyOnError(true)
    xfer.EnableEncodings(false)
    xfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    xfer.InitClientCertificates()
    xfer.EnablePeerVerification(false)
    xfer.SetMinimumTransferRate(1, 10)
    xfer.AddHeader("Accept", "image/jpeg,image/png,image/gif,image/webp;q=0.8,*/*;q=0.1")
    xfer.AddHeader("OCS-APIREQUEST", "true")
    xfer.AddHeader("User-Agent", "MemoriesRoku/1.4")

    if cfg.authMode = "account"
        user = ValidStr(cfg.username)
        pass = ValidStr(cfg.password)
        if user = "" or pass = ""
            out.error = "no creds"
            print "MEMORIES dl missing username/password"
            return out
        end if
        ' AsyncGetToFile frequently ignores Authorization alone — SetUserAndPassword required
        xfer.SetUserAndPassword(user, pass)
        ba = CreateObject("roByteArray")
        ba.FromAsciiString(user + ":" + pass)
        xfer.AddHeader("Authorization", "Basic " + ba.ToBase64String())
        print "MEMORIES dl basic-auth user="; user
    else if cfg.authMode = "share"
        print "MEMORIES dl share token len="; Len(ValidStr(cfg.token))
    end if

    ' Account mode: sync GetToFile is reliable with SetUserAndPassword; async often hangs.
    useSync = (cfg.authMode = "account")
    if not useSync and xfer.AsyncGetToFile(path)
        timeoutMs = 20000
        clock = CreateObject("roTimespan")
        clock.Mark()
        while clock.TotalMilliseconds() < timeoutMs
            msg = wait(500, port)
            if type(msg) = "roUrlEvent"
                return ApiConsumeDownloadEvent(msg, fs, path, out)
            end if
        end while
        print "MEMORIES dl hard-timeout AsyncCancel"
        xfer.AsyncCancel()
        msg = wait(1500, port)
        if type(msg) = "roUrlEvent"
            code = msg.GetResponseCode()
            if code >= 200 and code < 300 and fs.Exists(path)
                return ApiConsumeDownloadEvent(msg, fs, path, out)
            end if
            out.code = code
            out.reason = ValidStr(msg.GetFailureReason())
        end if
        if fs.Exists(path) then fs.Delete(path)
        out.error = "dl-timeout"
        out.code = -1
        return out
    end if

    print "MEMORIES dl sync GetToFile auth="; auth
    code = xfer.GetToFile(path)
    out.code = code
    reason = ""
    if code <= 0 then reason = ValidStr(xfer.GetFailureReason())
    out.reason = reason
    print "MEMORIES dl sync done code="; code; " reason="; reason; " exists="; fs.Exists(path)

    if code >= 200 and code < 300
        if not fs.Exists(path)
            out.error = "dl empty"
            return out
        end if
        norm = ApiNormalizeDownloadedImage(path)
        if not norm.ok
            out.error = norm.error
            return out
        end if
        out.ok = true
        out.path = norm.path
        out.kind = norm.kind
        return out
    end if

    if fs.Exists(path) then fs.Delete(path)
    if code = 401 or code = 403 or code > 0
        out.error = "HTTP" + code.ToStr()
    else if reason <> ""
        out.error = "net:" + reason
    else
        out.error = "net"
    end if
    return out
end function

function ApiConsumeDownloadEvent(msg as object, fs as object, path as string, out as object) as object
    code = msg.GetResponseCode()
    reason = ValidStr(msg.GetFailureReason())
    out.code = code
    out.reason = reason
    headers = msg.GetResponseHeaders()
    if headers <> invalid
        ct = headers["Content-Type"]
        if ct = invalid then ct = headers["content-type"]
        if ct <> invalid then out.contentType = ValidStr(ct)
    end if
    print "MEMORIES dl event code="; code; " reason="; reason; " exists="; fs.Exists(path)

    if code >= 200 and code < 300
        if not fs.Exists(path)
            out.error = "dl empty"
            return out
        end if
        norm = ApiNormalizeDownloadedImage(path)
        if not norm.ok
            out.error = norm.error
            return out
        end if
        out.ok = true
        out.path = norm.path
        out.kind = norm.kind
        return out
    end if

    if fs.Exists(path) then fs.Delete(path)
    if code = 401 or code = 403 or code > 0
        out.error = "HTTP" + code.ToStr()
    else if reason <> ""
        out.error = "net:" + reason
    else
        out.error = "net"
    end if
    return out
end function

function EncodeUri(s as string) as string
    xfer = CreateObject("roUrlTransfer")
    return xfer.Escape(s)
end function
