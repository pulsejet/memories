sub Main()
    screen = CreateObject("roSGScreen")
    port = CreateObject("roMessagePort")
    screen.SetMessagePort(port)

    scene = screen.CreateScene("MainScene")
    screen.Show()

    while true
        msg = wait(0, port)
        if type(msg) = "roSGScreenEvent"
            if msg.IsScreenClosed() then return
        end if
    end while
end sub
