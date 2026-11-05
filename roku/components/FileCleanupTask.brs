sub init()
    m.top.functionName = "runCleanup"
end sub

' roFileSystem is MAIN/TASK only — never create it from the render thread.
sub runCleanup()
    paths = m.top.paths
    if paths = invalid then return
    fs = CreateObject("roFileSystem")
    if fs = invalid then return
    for each p in paths
        if p <> invalid and p <> "" and fs.Exists(p)
            fs.Delete(p)
        end if
    end for
end sub
