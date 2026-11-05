sub init()
    m.top.setFocus(true)
    m.status = m.top.findNode("status")
    m.dayList = m.top.findNode("dayList")
    m.dayList.observeField("itemSelected", "onDaySelected")
    m.days = []
    m.cfg = ConfigLoad()
    startLoad()
end sub

sub onDaySelected()
    openSelectedDay()
end sub

sub startLoad()
    m.status.visible = true
    m.status.text = "Loading days…"
    m.dayList.visible = false

    task = CreateObject("roSGNode", "ApiTask")
    task.observeField("response", "onDaysResponse")
    task.request = { action: "days", cfg: m.cfg, reverse: false }
    m.loadTask = task
    task.control = "RUN"
end sub

sub onDaysResponse()
    resp = m.loadTask.response
    m.loadTask = invalid
    if resp = invalid or not resp.ok
        err = "Could not load days"
        if resp <> invalid and resp.error <> "" then err = resp.error
        m.status.text = err + Chr(10) + "Press Back for modes"
        return
    end if

    m.days = resp.days
    if m.days = invalid or m.days.Count() = 0
        m.status.text = "No days with photos" + Chr(10) + "Press Back for modes"
        return
    end if

    content = CreateObject("roSGNode", "ContentNode")
    maxDays = 200
    n = m.days.Count()
    if n > maxDays then n = maxDays
    for i = 0 to n - 1
        day = m.days[i]
        item = CreateObject("roSGNode", "ContentNode")
        label = FormatDayId(day.dayid)
        count = 0
        if day.count <> invalid then count = day.count
        if count > 0
            item.title = label + "  ·  " + count.ToStr() + " items"
        else
            item.title = label
        end if
        item.addField("dayid", "integer", false)
        item.dayid = day.dayid
        content.appendChild(item)
    end for

    m.dayList.content = content
    m.status.visible = false
    m.dayList.visible = true
    m.dayList.setFocus(true)
end sub

sub openSelectedDay()
    if m.dayList = invalid or m.dayList.content = invalid then return
    idx = m.dayList.itemFocused
    item = m.dayList.content.getChild(idx)
    if item = invalid or item.dayid = invalid then return

    m.status.visible = true
    m.status.text = "Loading " + FormatDayId(item.dayid) + "…"
    m.dayList.visible = false

    task = CreateObject("roSGNode", "ApiTask")
    task.observeField("response", "onDayPhotosResponse")
    task.request = { action: "loadDay", cfg: m.cfg, dayId: item.dayid }
    m.dayTask = task
    m.pendingDayId = item.dayid
    task.control = "RUN"
end sub

sub onDayPhotosResponse()
    resp = m.dayTask.response
    m.dayTask = invalid
    if resp = invalid or not resp.ok
        err = "Could not load day"
        if resp <> invalid and resp.error <> "" then err = resp.error
        m.status.text = err
        m.dayList.visible = true
        m.status.visible = false
        m.dayList.setFocus(true)
        return
    end if

    photos = resp.photos
    if photos = invalid or photos.Count() = 0
        m.status.visible = true
        m.status.text = "No photos on this day (videos skipped)"
        m.dayList.visible = true
        m.dayList.setFocus(true)
        return
    end if

    m.top.openDay = {
        dayid: m.pendingDayId,
        photos: photos,
        label: FormatDayId(m.pendingDayId)
    }
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "back"
        m.top.backToMenu = true
        return true
    else if key = "OK" or key = "play"
        if m.dayList.visible
            openSelectedDay()
            return true
        end if
    else if key = "replay"
        startLoad()
        return true
    end if

    ' Let LabelList handle up/down when focused
    if m.dayList.visible then return false
    return true
end function
