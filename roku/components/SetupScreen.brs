sub init()
    m.top.setFocus(true)
    m.authMode = "share"
    m.focusIndex = 0
    m.busy = false
    m.editing = false
    m.editTarget = ""
    m.maskPassword = false
    m.kbDialog = invalid
    m.kbHandled = false

    m.formRoot = m.top.findNode("formRoot")
    m.sharePanel = m.top.findNode("sharePanel")
    m.accountPanel = m.top.findNode("accountPanel")
    m.shareUrl = m.top.findNode("shareUrl")
    m.serverUrl = m.top.findNode("serverUrl")
    m.username = m.top.findNode("username")
    m.password = m.top.findNode("password")
    m.shareUrlBox = m.top.findNode("shareUrlBox")
    m.serverUrlBox = m.top.findNode("serverUrlBox")
    m.usernameBox = m.top.findNode("usernameBox")
    m.passwordBox = m.top.findNode("passwordBox")
    m.shareModeBg = m.top.findNode("shareModeBg")
    m.accountModeBg = m.top.findNode("accountModeBg")
    m.saveBg = m.top.findNode("saveBg")
    m.status = m.top.findNode("status")
    m.hint = m.top.findNode("hint")

    m.values = { shareUrl: "", serverUrl: "", username: "", password: "" }

    cfg = ConfigLoad()
    if cfg.authMode = "account"
        setAuthMode("account")
        m.values.serverUrl = cfg.serverUrl
        if m.values.serverUrl = "" then m.values.serverUrl = cfg.origin
        m.values.username = cfg.username
        m.values.password = cfg.password
    else if cfg.authMode = "share" and cfg.token <> ""
        setAuthMode("share")
        if cfg.shareKind = "album"
            m.values.shareUrl = cfg.origin + "/apps/memories/a/" + cfg.token
        else
            m.values.shareUrl = cfg.origin + "/apps/memories/s/" + cfg.token
        end if
    else
        setAuthMode("share")
    end if

    refreshFieldLabels()
    applyFocus()
end sub

sub setAuthMode(mode as string)
    m.authMode = mode
    if mode = "share"
        m.sharePanel.visible = true
        m.accountPanel.visible = false
    else
        m.sharePanel.visible = false
        m.accountPanel.visible = true
    end if
end sub

function focusCount() as integer
    if m.authMode = "share" then return 4
    return 6
end function

function connectFocusIndex() as integer
    if m.authMode = "share" then return 3
    return 5
end function

function isTextFocus() as boolean
    if m.authMode = "share" then return m.focusIndex = 2
    return m.focusIndex >= 2 and m.focusIndex <= 4
end function

sub refreshFieldLabels()
    m.shareUrl.text = displayOrHint(m.values.shareUrl, "https://cloud.example.com/apps/memories/s/…")
    m.serverUrl.text = displayOrHint(m.values.serverUrl, "https://cloud.example.com")
    m.username.text = displayOrHint(m.values.username, "username")
    if m.values.password = ""
        m.password.text = "app password"
        m.password.color = "0x6A7384FF"
    else
        m.password.text = RepeatChar("•", m.values.password.Len())
        m.password.color = "0xFFFFFFFF"
    end if
end sub

function displayOrHint(value as string, hint as string) as string
    if value = ""
        return hint
    end if
    return value
end function

function RepeatChar(ch as string, n as integer) as string
    s = ""
    i = 0
    while i < n
        s = s + ch
        i = i + 1
    end while
    return s
end function

sub clearBoxHighlights()
    inactive = "0x1C2230FF"
    if m.shareUrlBox <> invalid then m.shareUrlBox.color = inactive
    if m.serverUrlBox <> invalid then m.serverUrlBox.color = inactive
    if m.usernameBox <> invalid then m.usernameBox.color = inactive
    if m.passwordBox <> invalid then m.passwordBox.color = inactive
end sub

sub applyFocus()
    if m.editing then return

    active = "0x2A4A8AFF"
    m.saveBg.color = "0x3D6BF5FF"
    clearBoxHighlights()

    if m.authMode = "share"
        m.shareModeBg.color = "0x3D6BF5FF"
        m.accountModeBg.color = "0x2A3140FF"
    else
        m.shareModeBg.color = "0x2A3140FF"
        m.accountModeBg.color = "0x3D6BF5FF"
    end if

    m.top.setFocus(true)
    refreshFieldLabels()

    if m.values.shareUrl = "" then m.shareUrl.color = "0x6A7384FF" else m.shareUrl.color = "0xFFFFFFFF"
    if m.values.serverUrl = "" then m.serverUrl.color = "0x6A7384FF" else m.serverUrl.color = "0xFFFFFFFF"
    if m.values.username = "" then m.username.color = "0x6A7384FF" else m.username.color = "0xFFFFFFFF"

    if m.focusIndex = 0
        m.shareModeBg.color = "0x5A84FFFF"
        m.hint.text = "OK: use Share link"
    else if m.focusIndex = 1
        m.accountModeBg.color = "0x5A84FFFF"
        m.hint.text = "OK: use Account"
    else if m.focusIndex = connectFocusIndex()
        m.saveBg.color = "0x5A84FFFF"
        m.hint.text = "OK: connect"
    else if m.authMode = "share" and m.focusIndex = 2
        m.shareUrlBox.color = active
        m.hint.text = "OK: edit share URL · dialog OK/Cancel"
    else if m.authMode = "account" and m.focusIndex = 2
        m.serverUrlBox.color = active
        m.hint.text = "OK: edit server URL · dialog OK/Cancel"
    else if m.authMode = "account" and m.focusIndex = 3
        m.usernameBox.color = active
        m.hint.text = "OK: edit username · dialog OK/Cancel"
    else if m.authMode = "account" and m.focusIndex = 4
        m.passwordBox.color = active
        m.hint.text = "OK: edit app password · dialog OK/Cancel"
    end if
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false
    if m.busy then return true
    if m.editing then return true

    if key = "left" and m.focusIndex = 1
        m.focusIndex = 0
        applyFocus()
        return true
    else if key = "right" and m.focusIndex = 0
        m.focusIndex = 1
        applyFocus()
        return true
    else if key = "down"
        max = focusCount() - 1
        if m.focusIndex < max
            m.focusIndex = m.focusIndex + 1
            applyFocus()
        end if
        return true
    else if key = "up"
        if m.focusIndex > 0
            m.focusIndex = m.focusIndex - 1
            applyFocus()
        end if
        return true
    else if key = "OK"
        return handleOk()
    end if
    return false
end function

function handleOk() as boolean
    if m.focusIndex = 0
        setAuthMode("share")
        m.focusIndex = 2
        applyFocus()
        return true
    end if
    if m.focusIndex = 1
        setAuthMode("account")
        m.focusIndex = 2
        applyFocus()
        return true
    end if
    if m.focusIndex = connectFocusIndex()
        doConnect()
        return true
    end if
    if isTextFocus()
        openKeyboard()
        return true
    end if
    return true
end function

sub openKeyboard()
    title = "Edit"
    text = ""
    m.maskPassword = false
    m.editTarget = ""

    if m.authMode = "share" and m.focusIndex = 2
        title = "Share URL"
        text = m.values.shareUrl
        m.editTarget = "shareUrl"
    else if m.authMode = "account" and m.focusIndex = 2
        title = "Nextcloud URL"
        text = m.values.serverUrl
        m.editTarget = "serverUrl"
    else if m.authMode = "account" and m.focusIndex = 3
        title = "Username"
        text = m.values.username
        m.editTarget = "username"
    else if m.authMode = "account" and m.focusIndex = 4
        title = "App password"
        text = m.values.password
        m.editTarget = "password"
        m.maskPassword = true
    else
        return
    end if

    dialog = CreateObject("roSGNode", "KeyboardDialog")
    dialog.title = title
    dialog.text = text
    dialog.buttons = ["OK", "Cancel"]
    if dialog.textEditBox <> invalid
        ' Default clearOnDownKey=true wipes text when moving focus to OK/Cancel.
        dialog.textEditBox.clearOnDownKey = false
        if m.editTarget = "shareUrl" or m.editTarget = "serverUrl"
            dialog.textEditBox.maxTextLength = 256
        else if m.editTarget = "password"
            dialog.textEditBox.maxTextLength = 128
        end if
        if m.maskPassword then dialog.textEditBox.secureMode = true
    end if
    ' buttonSelected defaults to 0; without alwaysNotify, first OK may not notify.
    dialog.observeField("buttonSelected", "onKbDialogButton")
    dialog.observeField("wasClosed", "onKbDialogClosed")

    m.kbDialog = dialog
    m.kbHandled = false
    m.editing = true
    m.top.getScene().dialog = dialog
end sub

function readKbDialogText(dialog as object) as string
    if dialog = invalid then return ""
    ' Prefer textEditBox — KeyboardDialog.text can lag or clear on dismiss.
    if dialog.textEditBox <> invalid and dialog.textEditBox.text <> invalid
        return dialog.textEditBox.text
    end if
    if dialog.text <> invalid then return dialog.text
    return ""
end function

sub onKbDialogButton()
    if m.kbDialog = invalid or m.kbHandled then return
    m.kbHandled = true
    idx = m.kbDialog.buttonSelected
    value = readKbDialogText(m.kbDialog)
    target = m.editTarget
    dismissKbDialog()
    if idx = 0
        saveEditValue(target, value)
        advanceFocusAfterEdit(target)
    end if
    finishEdit()
end sub

sub onKbDialogClosed()
    if m.kbDialog = invalid or m.kbHandled then return
    m.kbHandled = true
    dismissKbDialog()
    finishEdit()
end sub

sub dismissKbDialog()
    scene = m.top.getScene()
    if scene <> invalid then scene.dialog = invalid
    m.kbDialog = invalid
end sub

sub saveEditValue(target as string, value as string)
    if target = "shareUrl"
        m.values.shareUrl = value
    else if target = "serverUrl"
        m.values.serverUrl = value
    else if target = "username"
        m.values.username = value
    else if target = "password"
        m.values.password = value
    else
        return
    end if
    refreshFieldLabels()
    m.status.color = "0x6BCB7AFF"
    if target = "password"
        m.status.text = "Saved app password"
    else if target = "username"
        m.status.text = "Saved username: " + TruncateStatus(value, 40)
    else
        m.status.text = "Saved: " + TruncateStatus(value, 48)
    end if
end sub

function TruncateStatus(value as string, maxLen as integer) as string
    if value = "" then return "(empty)"
    if value.Len() <= maxLen then return value
    return Left(value, maxLen - 1) + "…"
end function

sub advanceFocusAfterEdit(target as string)
    if target = "shareUrl"
        m.focusIndex = connectFocusIndex()
    else if target = "serverUrl"
        m.focusIndex = 3
    else if target = "username"
        m.focusIndex = 4
    else if target = "password"
        m.focusIndex = connectFocusIndex()
    end if
end sub

sub finishEdit()
    m.editing = false
    m.editTarget = ""
    m.top.setFocus(true)
    applyFocus()
end sub

sub doConnect()
    m.busy = true
    m.status.color = "0xA8B0C0FF"
    m.status.text = "Connecting…"

    cfg = invalid
    if m.authMode = "share"
        parsed = ParseShareUrl(m.values.shareUrl)
        if not parsed.ok
            finishError(parsed.error)
            return
        end if
        cfg = {
            authMode: "share",
            origin: parsed.origin,
            token: parsed.token,
            shareKind: parsed.shareKind,
            username: "",
            password: "",
            serverUrl: ""
        }
    else
        parsed = ParseServerUrl(m.values.serverUrl)
        if not parsed.ok
            finishError(parsed.error)
            return
        end if
        if m.values.username.Trim() = "" or m.values.password.Trim() = ""
            finishError("Enter username and app password")
            return
        end if
        cfg = {
            authMode: "account",
            origin: parsed.origin,
            token: "",
            shareKind: "",
            username: m.values.username.Trim(),
            password: m.values.password.Trim(),
            serverUrl: m.values.serverUrl.Trim()
        }
    end if

    task = CreateObject("roSGNode", "ApiTask")
    task.observeField("response", "onValidateResponse")
    task.request = { action: "validate", cfg: cfg }
    m.pendingCfg = cfg
    m.validateTask = task
    task.control = "RUN"
end sub

sub onValidateResponse()
    resp = m.validateTask.response
    m.validateTask = invalid
    if resp = invalid or not resp.ok
        err = "Connection failed"
        if resp <> invalid and resp.error <> "" then err = resp.error
        finishError(err)
        return
    end if

    ConfigSave(m.pendingCfg)
    m.pendingCfg = invalid
    m.status.color = "0x6BCB7AFF"
    m.status.text = "Connected"
    m.busy = false
    m.top.done = true
end sub

sub finishError(msg as string)
    m.status.color = "0xE8A05AFF"
    m.status.text = msg
    m.busy = false
end sub
