sub init()
    m.top.backgroundURI = ""
    m.top.backgroundColor = "0x12151CFF"
    m.host = m.top.findNode("screenHost")
    m.current = invalid
    m.pendingDay = invalid
    showInitial()
end sub

sub showInitial()
    cfg = ConfigLoad()
    if ConfigIsReady(cfg)
        showModeMenu()
    else
        showSetup()
    end if
end sub

sub clearHost()
    if m.current <> invalid
        m.host.removeChild(m.current)
        m.current = invalid
    end if
end sub

sub showSetup()
    clearHost()
    screen = CreateObject("roSGNode", "SetupScreen")
    screen.observeField("done", "onSetupDone")
    m.host.appendChild(screen)
    m.current = screen
    screen.setFocus(true)
end sub

sub showModeMenu()
    clearHost()
    m.pendingDay = invalid
    screen = CreateObject("roSGNode", "ModeMenuScreen")
    screen.observeField("choice", "onModeChoice")
    m.host.appendChild(screen)
    m.current = screen
    screen.setFocus(true)
end sub

sub showSlideshow(mode as string)
    clearHost()
    screen = CreateObject("roSGNode", "SlideshowScreen")
    screen.observeField("backToMenu", "onSlideshowBack")
    screen.observeField("changeShare", "onChangeShare")
    screen.mode = mode
    m.host.appendChild(screen)
    m.current = screen
    screen.setFocus(true)
    screen.start = true
end sub

sub showDaySlideshow(dayInfo as object)
    clearHost()
    screen = CreateObject("roSGNode", "SlideshowScreen")
    screen.observeField("backToMenu", "onDaySlideshowBack")
    screen.observeField("changeShare", "onChangeShare")
    screen.mode = "manual"
    if dayInfo.label <> invalid then screen.dayLabel = dayInfo.label
    if dayInfo.photos <> invalid then screen.seedPhotos = dayInfo.photos
    m.host.appendChild(screen)
    m.current = screen
    screen.setFocus(true)
    screen.start = true
end sub

sub showTimelineBrowser()
    clearHost()
    screen = CreateObject("roSGNode", "TimelineBrowserScreen")
    screen.observeField("backToMenu", "onTimelineBack")
    screen.observeField("openDay", "onTimelineOpenDay")
    m.host.appendChild(screen)
    m.current = screen
    screen.setFocus(true)
end sub

sub onSetupDone()
    if m.current <> invalid and m.current.done = true
        showModeMenu()
    end if
end sub

sub onModeChoice()
    if m.current = invalid then return
    choice = m.current.choice
    if choice = "setup"
        showSetup()
    else if choice = "timeline"
        showTimelineBrowser()
    else if choice = "newest" or choice = "random"
        showSlideshow(choice)
    end if
end sub

sub onSlideshowBack()
    if m.current <> invalid and m.current.backToMenu = true
        showModeMenu()
    end if
end sub

sub onDaySlideshowBack()
    if m.current <> invalid and m.current.backToMenu = true
        showTimelineBrowser()
    end if
end sub

sub onTimelineBack()
    if m.current <> invalid and m.current.backToMenu = true
        showModeMenu()
    end if
end sub

sub onTimelineOpenDay()
    if m.current = invalid then return
    dayInfo = m.current.openDay
    if dayInfo = invalid then return
    showDaySlideshow(dayInfo)
end sub

sub onChangeShare()
    if m.current <> invalid and m.current.changeShare = true
        showSetup()
    end if
end sub
