sub init()
    m.top.setFocus(true)
    m.photo = m.top.findNode("photo")
    m.caption = m.top.findNode("caption")
    m.hint = m.top.findNode("hint")
    m.status = m.top.findNode("status")
    m.overlay = m.top.findNode("overlay")
    m.timer = m.top.findNode("advanceTimer")
    m.loadWatchdog = m.top.findNode("loadWatchdog")
    m.failSkipTimer = m.top.findNode("failSkipTimer")
    m.decodePoll = m.top.findNode("decodePoll")
    m.photos = []
    m.index = 0
    m.reqToken = 0
    m.expectToken = 0
    m.expectFileid = ""
    m.imageTask = invalid
    m.currentImagePath = ""
    m.pendingPath = ""
    m.stalePath = ""
    m.displayReady = false
    m.tmpSlot = 0
    m.tmpGen = 0
    m.mode = "newest"
    m.autoAdvance = true
    m.started = false
    m.advancingOnFail = false
    m.lastDlCode = 0
    m.retryCount = 0
    m.loadPhase = "" ' dl | decode | ""

    m.photo.observeField("loadStatus", "onPhotoLoadStatus")
    m.timer.observeField("fire", "onAdvanceFire")
    m.loadWatchdog.observeField("fire", "onLoadWatchdog")
    m.failSkipTimer.observeField("fire", "onFailSkipFire")
    m.decodePoll.observeField("fire", "onDecodePoll")
    m.top.observeField("start", "onStart")
end sub

sub onStart()
    if m.started then return
    if m.top.start <> true then return
    m.started = true

    m.cfg = ConfigLoad()
    m.mode = "newest"
    if m.top.mode <> invalid and m.top.mode <> ""
        m.mode = m.top.mode
    end if

    seed = m.top.seedPhotos
    if seed <> invalid and GetInterface(seed, "ifArray") <> invalid and seed.Count() > 0
        m.mode = "manual"
        m.autoAdvance = false
        m.photos = seed
        m.index = 0
        m.status.visible = false
        m.overlay.visible = true
        updateHint()
        showCurrent()
        return
    end if

    m.autoAdvance = true
    updateHint()
    startLoad()
end sub

sub updateHint()
    if m.mode = "manual"
        label = m.top.dayLabel
        prefix = "Left/Right  ·  Back: timeline"
        if label <> invalid and label <> ""
            m.hint.text = label + "  ·  " + prefix
        else
            m.hint.text = prefix
        end if
    else if m.mode = "random"
        m.hint.text = "Random  ·  Left/Right  ·  Back: modes  ·  *: connection"
    else
        m.hint.text = "Newest first  ·  Left/Right  ·  Back: modes  ·  *: connection"
    end if
end sub

' roFileSystem is illegal on the render thread — delete via Task only.
sub enqueueFileCleanup(paths as object)
    if paths = invalid or paths.Count() = 0 then return
    cleaned = []
    for each p in paths
        if p <> invalid and p <> "" and not isProtectedTmpPath(p) then cleaned.Push(p)
    end for
    if cleaned.Count() = 0 then return
    task = CreateObject("roSGNode", "FileCleanupTask")
    task.paths = cleaned
    task.control = "RUN"
end sub

sub deleteTempImage(path as string)
    if path = invalid or path = "" then return
    enqueueFileCleanup([path])
end sub

function isProtectedTmpPath(path as string) as boolean
    if path = "" then return false
    if path = m.currentImagePath then return true
    if path = m.pendingPath then return true
    if path = m.stalePath then return true
    return false
end function

' Two slots: never delete the file Poster is showing/decoding.
function nextTmpPath() as string
    m.tmpSlot = 1 - m.tmpSlot
    m.tmpGen = m.tmpGen + 1
    if m.tmpSlot = 0
        slot = "a"
    else
        slot = "b"
    end if
    ' Extension may change after sniff; start as .bin then normalize
    path = "tmp:/memories_" + slot + "_" + m.tmpGen.ToStr() + ".bin"
    return path
end function

sub clearAllTmpImages()
    paths = []
    if m.pendingPath <> "" then paths.Push(m.pendingPath)
    if m.currentImagePath <> "" then paths.Push(m.currentImagePath)
    if m.stalePath <> "" then paths.Push(m.stalePath)
    g = m.tmpGen
    while g >= 1 and (m.tmpGen - g) < 16
        for each slot in ["a", "b"]
            for each ext in ["bin", "jpg", "png", "gif", "webp"]
                paths.Push("tmp:/memories_" + slot + "_" + g.ToStr() + "." + ext)
            end for
        end for
        g = g - 1
    end while
    m.pendingPath = ""
    m.currentImagePath = ""
    m.stalePath = ""
    enqueueFileCleanup(paths)
end sub

sub stopTimer(t as object)
    if t <> invalid then t.control = "stop"
end sub

sub restartTimer(t as object)
    if t = invalid then return
    t.control = "stop"
    t.control = "start"
end sub

sub stopAdvanceTimer()
    stopTimer(m.timer)
end sub

sub restartAdvanceTimer()
    if not m.autoAdvance then return
    restartTimer(m.timer)
end sub

sub stopLoadWatchdog()
    stopTimer(m.loadWatchdog)
end sub

sub startLoadWatchdog()
    restartTimer(m.loadWatchdog)
end sub

sub stopFailSkipTimer()
    stopTimer(m.failSkipTimer)
end sub

sub startFailSkipTimer()
    restartTimer(m.failSkipTimer)
end sub

sub stopDecodePoll()
    stopTimer(m.decodePoll)
end sub

sub startDecodePoll()
    restartTimer(m.decodePoll)
end sub

sub setCaptionState(state as string)
    ' Always keep overlay + caption readable on black, even while blank.
    if m.overlay <> invalid then m.overlay.visible = true
    if m.photos.Count() = 0
        m.caption.text = state
        return
    end if
    base = (m.index + 1).ToStr() + "/" + m.photos.Count().ToStr()
    item = m.photos[m.index]
    if item <> invalid and item.basename <> ""
        base = base + "  ·  " + item.basename
    end if
    if state <> ""
        m.caption.text = base + "  ·  " + state
    else
        m.caption.text = base
    end if
end sub

sub showErrorBanner(msg as string)
    if m.status = invalid then return
    m.status.visible = true
    m.status.text = msg
end sub

sub hideErrorBanner()
    if m.status = invalid then return
    m.status.visible = false
end sub

function idKey(v as dynamic) as string
    return ValidStr(v)
end function

sub startLoad()
    m.status.visible = true
    m.status.text = "Loading photos…"
    m.overlay.visible = false
    clearPoster()
    stopAdvanceTimer()
    stopLoadWatchdog()
    stopFailSkipTimer()
    stopDecodePoll()
    cancelImageTask()
    clearAllTmpImages()

    task = CreateObject("roSGNode", "ApiTask")
    task.observeField("response", "onTimelineResponse")
    task.request = { action: "loadTimeline", cfg: m.cfg, reverse: false }
    m.loadTask = task
    task.control = "RUN"
end sub

sub onTimelineResponse()
    resp = m.loadTask.response
    m.loadTask = invalid
    if resp = invalid or not resp.ok
        err = "Could not load photos"
        if resp <> invalid and resp.error <> "" then err = resp.error
        showEmpty(err)
        return
    end if

    m.photos = resp.photos
    if m.photos = invalid or m.photos.Count() = 0
        showEmpty("No photos in this share")
        return
    end if

    if m.mode = "random"
        ShuffleArray(m.photos)
    end if

    m.index = 0
    m.status.visible = false
    m.overlay.visible = true
    updateHint()
    showCurrent()
end sub

sub showEmpty(msg as string)
    m.status.visible = true
    m.status.text = msg + Chr(10) + "Press Back for modes"
    m.overlay.visible = false
    clearPoster()
    stopAdvanceTimer()
    stopLoadWatchdog()
    stopFailSkipTimer()
    stopDecodePoll()
end sub

sub clearPoster()
    m.displayReady = false
    stopDecodePoll()
    m.photo.uri = ""
end sub

sub cancelImageTask()
    stopLoadWatchdog()
    stopDecodePoll()
    if m.imageTask <> invalid
        m.imageTask.unobserveField("response")
        m.imageTask.control = "STOP"
        m.imageTask = invalid
    end if
    if m.pendingPath <> ""
        if m.pendingPath <> m.currentImagePath
            deleteTempImage(m.pendingPath)
        end if
        m.pendingPath = ""
    end if
end sub

sub showCurrent()
    if m.photos.Count() = 0 then return
    if m.index < 0 then m.index = m.photos.Count() - 1
    if m.index >= m.photos.Count() then m.index = 0

    item = m.photos[m.index]
    m.lastDlCode = 0
    hideErrorBanner()
    m.loadPhase = "dl"
    auth = "?"
    host = "?"
    if m.cfg <> invalid
        auth = ValidStr(m.cfg.authMode)
        if auth = "" then auth = "?"
        host = ApiHostOf(m.cfg)
    end if
    setCaptionState("dl… " + auth + " " + host)
    print "MEMORIES showCurrent fileid="; idKey(item.fileid); " phase=dl auth="; auth; " host="; host

    m.reqToken = m.reqToken + 1
    token = m.reqToken
    m.advancingOnFail = false
    stopFailSkipTimer()
    stopAdvanceTimer()
    cancelImageTask()
    m.displayReady = false
    m.loadPhase = "dl"

    path = nextTmpPath()
    m.pendingPath = path
    task = CreateObject("roSGNode", "ImageLoadTask")
    task.observeField("response", "onImageResponse")
    mimetype = ""
    if item.mimetype <> invalid then mimetype = item.mimetype
    task.request = {
        cfg: m.cfg,
        fileid: item.fileid,
        path: path,
        token: token,
        mimetype: mimetype
    }
    m.expectToken = token
    m.expectFileid = idKey(item.fileid)
    m.imageTask = task
    task.control = "RUN"
    startLoadWatchdog()
end sub

sub onImageResponse()
    task = m.imageTask
    if task = invalid then return
    resp = task.response
    ' Keep task on invalid notify — clearing here drops the real payload forever
    if resp = invalid then return
    m.imageTask = invalid
    stopLoadWatchdog()

    print "MEMORIES onImageResponse ok="; resp.ok; " code="; resp.code; " path="; ValidStr(resp.path)
    if resp.code <> invalid then m.lastDlCode = resp.code

    ' Drop stale responses (string compare — SG may coerce ints to floats)
    if resp.token <> invalid and idKey(resp.token) <> idKey(m.expectToken)
        print "[slideshow] stale token got="; idKey(resp.token); " expect="; idKey(m.expectToken)
        if resp.path <> "" and resp.path <> m.currentImagePath then deleteTempImage(resp.path)
        return
    end if
    if resp.fileid <> invalid and idKey(resp.fileid) <> m.expectFileid
        print "[slideshow] stale fileid"
        if resp.path <> "" and resp.path <> m.currentImagePath then deleteTempImage(resp.path)
        return
    end if

    if not resp.ok
        if m.pendingPath <> "" and m.pendingPath <> m.currentImagePath
            deleteTempImage(m.pendingPath)
        end if
        m.pendingPath = ""
        m.loadPhase = ""
        if resp.path <> "" and resp.path <> m.currentImagePath
            deleteTempImage(resp.path)
        end if
        err = "fail"
        if resp.error <> invalid and resp.error <> "" then err = resp.error
        ' Share mode: Poster can fetch preview URL with token query (no Basic auth needed)
        if m.cfg <> invalid and m.cfg.authMode = "share" and m.retryCount < 1
            shareUrl = ApiPreviewUrl(m.cfg, m.expectFileid)
            print "MEMORIES share Poster fallback "; shareUrl
            m.loadPhase = "decode"
            m.retryCount = m.retryCount + 1
            setCaptionState("share-http…")
            m.photo.uri = shareUrl
            startDecodePoll()
            startLoadWatchdog()
            return
        end if
        print "MEMORIES dl fail code="; m.lastDlCode; " err="; err
        skipOnFail(err)
        return
    end if

    ' Keep previous file until new one is ready
    if m.currentImagePath <> "" and m.currentImagePath <> resp.path
        m.stalePath = m.currentImagePath
    end if
    m.currentImagePath = resp.path
    m.pendingPath = ""
    m.displayReady = false
    m.loadPhase = "decode"
    hideErrorBanner()
    setCaptionState("decode…")
    print "MEMORIES decode path="; resp.path; " kind="; ValidStr(resp.kind)

    ' Unique tmpGen path is enough — query strings break tmp: Poster lookup
    m.photo.uri = resp.path
    startDecodePoll()

    status = m.photo.loadStatus
    if status = "ready" or status = "failed" or status = "error"
        onPhotoLoadStatus()
    end if
end sub

sub onDecodePoll()
    if m.displayReady then
        stopDecodePoll()
        return
    end if
    status = m.photo.loadStatus
    if status = "ready" or status = "failed" or status = "error"
        onPhotoLoadStatus()
    end if
end sub

sub skipOnFail(err as string)
    if m.advancingOnFail then return
    stopAdvanceTimer()
    stopLoadWatchdog()
    stopDecodePoll()
    msg = "fail"
    if err <> invalid and err <> "" then msg = err
    setCaptionState("fail:" + msg)
    banner = msg
    if m.lastDlCode <> 0 then banner = banner + "  (HTTP " + m.lastDlCode.ToStr() + ")"
    showErrorBanner(banner + Chr(10) + "Retrying…")
    ' One immediate retry on same photo, then skip
    if m.retryCount < 1
        m.retryCount = m.retryCount + 1
        m.advancingOnFail = true
        startFailSkipTimer()
        return
    end if
    m.retryCount = 0
    if m.photos.Count() <= 1 then return
    m.advancingOnFail = true
    startFailSkipTimer()
end sub

sub onFailSkipFire()
    m.advancingOnFail = false
    if m.retryCount > 0
        ' Retry same index once
        showCurrent()
        return
    end if
    if m.photos.Count() <= 1 then return
    nextPhoto()
end sub

sub onLoadWatchdog()
    ' Hard fail-safe: still not ready after 12s
    if m.displayReady then return
    phase = m.loadPhase
    print "MEMORIES watchdog fire phase="; phase; " displayReady="; m.displayReady
    cancelImageTask()
    m.loadPhase = ""
    if phase = "dl" or phase = ""
        ' Download never signaled — skip immediately (no retry loop)
        setCaptionState("fail:dl-timeout")
        showErrorBanner("Download timed out" + Chr(10) + "Skipping…")
        m.retryCount = 0
        if m.photos.Count() <= 1 then return
        m.advancingOnFail = true
        startFailSkipTimer()
        return
    end if
    skipOnFail("timeout")
end sub

sub onPhotoLoadStatus()
    status = m.photo.loadStatus
    if status = "ready"
        if m.displayReady then return
        m.displayReady = true
        m.loadPhase = ""
        m.retryCount = 0
        stopLoadWatchdog()
        stopDecodePoll()
        hideErrorBanner()
        if m.stalePath <> "" and m.stalePath <> m.currentImagePath
            deleteTempImage(m.stalePath)
        end if
        m.stalePath = ""
        setCaptionState("ready")
        print "MEMORIES ready "; m.currentImagePath
        restartAdvanceTimer()
    else if status = "failed" or status = "error"
        bad = m.currentImagePath
        m.displayReady = false
        m.loadPhase = ""
        stopLoadWatchdog()
        stopDecodePoll()
        clearPoster()
        if bad <> "" and bad <> m.stalePath
            deleteTempImage(bad)
        end if
        m.currentImagePath = ""
        print "MEMORIES poster "; status
        skipOnFail("display")
    end if
end sub

sub nextPhoto()
    if m.photos.Count() = 0 then return
    m.retryCount = 0
    m.index = m.index + 1
    if m.index >= m.photos.Count()
        if m.mode = "random"
            ShuffleArray(m.photos)
        end if
        m.index = 0
    end if
    showCurrent()
end sub

sub prevPhoto()
    if m.photos.Count() = 0 then return
    m.retryCount = 0
    m.index = m.index - 1
    if m.index < 0 then m.index = m.photos.Count() - 1
    showCurrent()
end sub

sub onAdvanceFire()
    if m.autoAdvance and m.displayReady then nextPhoto()
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "back"
        stopAdvanceTimer()
        stopLoadWatchdog()
        stopFailSkipTimer()
        stopDecodePoll()
        cancelImageTask()
        clearAllTmpImages()
        clearPoster()
        m.top.backToMenu = true
        return true
    else if key = "options"
        stopAdvanceTimer()
        stopLoadWatchdog()
        stopFailSkipTimer()
        stopDecodePoll()
        cancelImageTask()
        clearAllTmpImages()
        clearPoster()
        m.top.changeShare = true
        return true
    else if key = "right" or key = "OK" or key = "play"
        m.advancingOnFail = false
        stopFailSkipTimer()
        nextPhoto()
        return true
    else if key = "left"
        m.advancingOnFail = false
        stopFailSkipTimer()
        prevPhoto()
        return true
    else if key = "replay"
        if m.mode = "manual"
            m.index = 0
            showCurrent()
        else
            startLoad()
        end if
        return true
    end if
    return false
end function
