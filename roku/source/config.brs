' Persistent config + share / account URL parsing.
' Registry section: memories_roku

function ConfigRegistry() as object
    sec = CreateObject("roRegistrySection", "memories_roku")
    return sec
end function

function ConfigClear() as void
    sec = ConfigRegistry()
    keys = ["authMode", "origin", "token", "shareKind", "username", "password", "serverUrl", "mode"]
    for each key in keys
        if sec.Exists(key) then sec.Delete(key)
    end for
    sec.Flush()
end function

function ConfigLoad() as object
    sec = ConfigRegistry()
    cfg = {
        authMode: "",
        origin: "",
        token: "",
        shareKind: "",
        username: "",
        password: "",
        serverUrl: "",
        mode: "newest"
    }
    if sec.Exists("authMode") then cfg.authMode = sec.Read("authMode")
    if sec.Exists("origin") then cfg.origin = sec.Read("origin")
    if sec.Exists("token") then cfg.token = sec.Read("token")
    if sec.Exists("shareKind") then cfg.shareKind = sec.Read("shareKind")
    if sec.Exists("username") then cfg.username = sec.Read("username")
    if sec.Exists("password") then cfg.password = sec.Read("password")
    if sec.Exists("serverUrl") then cfg.serverUrl = sec.Read("serverUrl")
    if sec.Exists("mode") then cfg.mode = sec.Read("mode")
    if cfg.mode <> "newest" and cfg.mode <> "random" and cfg.mode <> "timeline"
        cfg.mode = "newest"
    end if
    return cfg
end function

function ConfigSave(cfg as object) as void
    sec = ConfigRegistry()
    sec.Write("authMode", cfg.authMode)
    sec.Write("origin", cfg.origin)
    sec.Write("token", ValidStr(cfg.token))
    sec.Write("shareKind", ValidStr(cfg.shareKind))
    sec.Write("username", ValidStr(cfg.username))
    sec.Write("password", ValidStr(cfg.password))
    sec.Write("serverUrl", ValidStr(cfg.serverUrl))
    if cfg.mode <> invalid and cfg.mode <> ""
        sec.Write("mode", cfg.mode)
    end if
    sec.Flush()
end function

function ConfigSaveMode(mode as string) as void
    sec = ConfigRegistry()
    sec.Write("mode", mode)
    sec.Flush()
end function

function ConfigGetMode() as string
    cfg = ConfigLoad()
    return cfg.mode
end function

' dayid is Unix epoch day (UTC). Format for timeline list labels.
function FormatDayId(dayid as dynamic) as string
    if dayid = invalid then return "Unknown day"
    id = dayid
    if GetInterface(dayid, "ifString") <> invalid then id = dayid.ToInt()
    dt = CreateObject("roDateTime")
    dt.FromSeconds(id * 86400)
    months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
    m = dt.GetMonth()
    if m < 1 or m > 12 then return "Day " + id.ToStr()
    return months[m - 1] + " " + dt.GetDayOfMonth().ToStr() + ", " + dt.GetYear().ToStr()
end function

' Fisher–Yates in place. BrightScript Rnd(n) returns 1..n.
sub ShuffleArray(arr as object)
    if arr = invalid then return
    n = arr.Count()
    if n < 2 then return
    for i = n - 1 to 1 step -1
        j = Rnd(i + 1) - 1
        tmp = arr[i]
        arr[i] = arr[j]
        arr[j] = tmp
    end for
end sub

function ConfigIsReady(cfg as object) as boolean
    if cfg = invalid then return false
    if cfg.authMode = "share"
        return cfg.origin <> "" and cfg.token <> "" and cfg.shareKind <> ""
    else if cfg.authMode = "account"
        return cfg.origin <> "" and cfg.username <> "" and cfg.password <> ""
    end if
    return false
end function

function ValidStr(v as dynamic) as string
    if v = invalid then return ""
    if GetInterface(v, "ifString") <> invalid then return v
    return v.ToStr()
end function

' Parse a Memories / Nextcloud public share URL.
' Returns { ok, error, origin, token, shareKind } where shareKind is "folder" or "album".
function ParseShareUrl(raw as string) as object
    result = { ok: false, error: "", origin: "", token: "", shareKind: "" }
    url = raw.Trim()
    if url = ""
        result.error = "Paste a share link"
        return result
    end if

    if Left(url, 7) <> "http://" and Left(url, 8) <> "https://"
        url = "https://" + url
    end if

    ' Strip fragment
    hash = Instr(1, url, "#")
    if hash > 0 then url = Left(url, hash - 1)

    ' Album: .../apps/memories/a/{token}
    albumIdx = Instr(1, LCase(url), "/apps/memories/a/")
    if albumIdx > 0
        after = Mid(url, albumIdx + Len("/apps/memories/a/"))
        token = TokenFromPath(after)
        origin = OriginFromUrl(url)
        if origin = "" or token = ""
            result.error = "Could not parse album share URL"
            return result
        end if
        result.ok = true
        result.origin = origin
        result.token = token
        result.shareKind = "album"
        return result
    end if

    ' Folder via Memories: .../apps/memories/s/{token}
    memS = Instr(1, LCase(url), "/apps/memories/s/")
    if memS > 0
        after = Mid(url, memS + Len("/apps/memories/s/"))
        token = TokenFromPath(after)
        origin = OriginFromUrl(url)
        if origin = "" or token = ""
            result.error = "Could not parse folder share URL"
            return result
        end if
        result.ok = true
        result.origin = origin
        result.token = token
        result.shareKind = "folder"
        return result
    end if

    ' Generic Nextcloud public share: .../s/{token} (not /apps/memories/a/)
    sIdx = Instr(1, url, "/s/")
    if sIdx > 0
        after = Mid(url, sIdx + 3)
        token = TokenFromPath(after)
        origin = OriginFromUrl(url)
        if origin = "" or token = ""
            result.error = "Could not parse share URL"
            return result
        end if
        result.ok = true
        result.origin = origin
        result.token = token
        result.shareKind = "folder"
        return result
    end if

    result.error = "Use a Memories share link (/s/... or /a/...)"
    return result
end function

' Parse account server URL into origin (scheme + host[:port]).
function ParseServerUrl(raw as string) as object
    result = { ok: false, error: "", origin: "" }
    url = raw.Trim()
    if url = ""
        result.error = "Enter your Nextcloud URL"
        return result
    end if
    if Left(url, 7) <> "http://" and Left(url, 8) <> "https://"
        url = "https://" + url
    end if
    origin = OriginFromUrl(url)
    if origin = ""
        result.error = "Invalid server URL"
        return result
    end if
    result.ok = true
    result.origin = origin
    return result
end function

function TokenFromPath(after as string) as string
    path = after
    q = Instr(1, path, "?")
    if q > 0 then path = Left(path, q - 1)
    ' first path segment
    slash = Instr(1, path, "/")
    if slash > 0 then path = Left(path, slash - 1)
    return path.Trim()
end function

function OriginFromUrl(url as string) as string
    ' https://host:port/...
    schemeEnd = Instr(1, url, "://")
    if schemeEnd = 0 then return ""
    rest = Mid(url, schemeEnd + 3)
    slash = Instr(1, rest, "/")
    if slash = 0
        host = rest
    else
        host = Left(rest, slash - 1)
    end if
    if host = "" then return ""
    return Left(url, schemeEnd + 2) + host
end function
