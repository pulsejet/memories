sub init()
    m.top.setFocus(true)
    m.rows = [
        m.top.findNode("row0"),
        m.top.findNode("row1"),
        m.top.findNode("row2"),
        m.top.findNode("row3")
    ]
    m.choices = ["newest", "random", "timeline", "setup"]
    m.focusIndex = modeToIndex(ConfigGetMode())
    applyFocus()
end sub

function modeToIndex(mode as string) as integer
    if mode = "random" then return 1
    if mode = "timeline" then return 2
    return 0
end function

sub applyFocus()
    inactive = "0x1C2230FF"
    active = "0x3D6BF5FF"
    for i = 0 to m.rows.Count() - 1
        if i = m.focusIndex
            m.rows[i].color = active
        else
            m.rows[i].color = inactive
        end if
    end for
    m.top.setFocus(true)
end sub

function onKeyEvent(key as string, press as boolean) as boolean
    if not press then return false

    if key = "up"
        if m.focusIndex > 0
            m.focusIndex = m.focusIndex - 1
            applyFocus()
        end if
        return true
    else if key = "down"
        if m.focusIndex < m.choices.Count() - 1
            m.focusIndex = m.focusIndex + 1
            applyFocus()
        end if
        return true
    else if key = "OK" or key = "play"
        choice = m.choices[m.focusIndex]
        if choice <> "setup"
            ConfigSaveMode(choice)
        end if
        m.top.choice = choice
        return true
    else if key = "options"
        m.top.choice = "setup"
        return true
    end if
    return false
end function
