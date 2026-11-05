sub init()
    m.top.functionName = "runDownload"
end sub

sub runDownload()
    req = m.top.request
    out = { ok: false, error: "", path: "", code: 0, fileid: 0, token: 0, kind: "" }
    print "MEMORIES ImageLoadTask run"
    if req = invalid or req.cfg = invalid or req.fileid = invalid
        out.error = "bad req"
        print "MEMORIES ImageLoadTask bad req"
        m.top.response = out
        return
    end if

    out.fileid = req.fileid
    if req.token <> invalid then out.token = req.token

    path = "tmp:/memories_dl.jpg"
    if req.path <> invalid and req.path <> "" then path = req.path

    mimetype = invalid
    if req.mimetype <> invalid then mimetype = req.mimetype

    print "MEMORIES ImageLoadTask fileid="; ValidStr(req.fileid); " auth="; ValidStr(req.cfg.authMode)
    r = ApiDownloadPreview(req.cfg, req.fileid, path, mimetype)
    out.ok = r.ok
    out.error = r.error
    out.code = r.code
    out.path = r.path
    if r.kind <> invalid then out.kind = r.kind
    print "MEMORIES ImageLoadTask done ok="; out.ok; " code="; out.code; " err="; out.error

    if not out.ok and path <> ""
        fs = CreateObject("roFileSystem")
        if fs <> invalid and fs.Exists(path) then fs.Delete(path)
        if out.path <> "" and out.path <> path and fs <> invalid and fs.Exists(out.path)
            fs.Delete(out.path)
        end if
    end if

    ' Single assign only — pulsing invalid first races observeField and drops the real result
    m.top.response = out
end sub
