sub init()
    m.top.functionName = "runRequest"
end sub

sub runRequest()
    req = m.top.request
    out = { ok: false, error: "Unknown request", action: "", json: invalid, photos: [], days: [], code: 0 }
    if req = invalid
        m.top.response = out
        return
    end if

    cfg = req.cfg
    action = req.action
    out.action = action

    if action = "validate"
        v = ApiValidateConfig(cfg)
        out.ok = v.ok
        out.error = v.error
    else if action = "days"
        reverse = false
        if req.reverse = true then reverse = true
        r = ApiFetchDays(cfg, reverse)
        out.ok = r.ok
        out.error = r.error
        out.code = r.code
        out.json = r.json
        out.days = DaysFromJson(r.json)
    else if action = "dayDetail"
        reverse = false
        if req.reverse = true then reverse = true
        r = ApiFetchDayDetail(cfg, req.dayIds, reverse)
        out.ok = r.ok
        out.error = r.error
        out.code = r.code
        out.json = r.json
        out.photos = PhotosFromDayJson(r.json)
    else if action = "loadTimeline"
        reverse = false
        if req.reverse = true then reverse = true
        out = LoadTimeline(cfg, reverse)
        out.action = action
    else if action = "loadDay"
        out = LoadSingleDay(cfg, req.dayId)
        out.action = action
    end if

    m.top.response = out
end sub

function DaysFromJson(json as dynamic) as object
    days = []
    if json = invalid or GetInterface(json, "ifArray") = invalid then return days
    for each day in json
        if day <> invalid and day.dayid <> invalid
            count = 0
            if day.count <> invalid then count = day.count
            days.Push({ dayid: day.dayid, count: count })
        end if
    end for
    return days
end function

function PhotosFromDayJson(json as dynamic) as object
    photos = []
    if json = invalid then return photos
    ' Response is array of photo objects for GET /days/{id}
    if GetInterface(json, "ifArray") <> invalid
        for each item in json
            AppendPhoto(photos, item)
        end for
        return photos
    end if
    return photos
end function

sub AppendPhoto(photos as object, item as dynamic)
    if item = invalid then return
    if item.isvideo = true or item.isvideo = 1 then return
    if item.fileid = invalid then return
    photos.Push({
        fileid: item.fileid,
        dayid: item.dayid,
        basename: ValidStr(item.basename),
        mimetype: ValidStr(item.mimetype),
        w: item.w,
        h: item.h
    })
end sub

function LoadTimeline(cfg as object, reverse as boolean) as object
    out = { ok: false, error: "", photos: [], code: 0, action: "loadTimeline" }
    daysResp = ApiFetchDays(cfg, reverse)
    out.code = daysResp.code
    if not daysResp.ok
        out.error = daysResp.error
        return out
    end if

    days = daysResp.json
    if days = invalid or GetInterface(days, "ifArray") = invalid
        out.error = "Unexpected days response"
        return out
    end if

    if days.Count() = 0
        out.ok = true
        out.error = ""
        out.photos = []
        return out
    end if

    photos = []
    batch = []
    maxPhotos = 400
    maxDays = 60
    dayCount = 0

    for each day in days
        if dayCount >= maxDays then exit for
        if photos.Count() >= maxPhotos then exit for
        if day <> invalid and day.dayid <> invalid
            batch.Push(day.dayid.ToStr())
            dayCount = dayCount + 1
            if batch.Count() >= 5
                MergeDayBatch(cfg, batch, photos, maxPhotos, reverse)
                batch = []
                if photos.Count() >= maxPhotos then exit for
            end if
        end if
    end for

    if batch.Count() > 0 and photos.Count() < maxPhotos
        MergeDayBatch(cfg, batch, photos, maxPhotos, reverse)
    end if

    out.ok = true
    out.photos = photos
    return out
end function

function LoadSingleDay(cfg as object, dayId as dynamic) as object
    out = { ok: false, error: "", photos: [], code: 0, action: "loadDay" }
    if dayId = invalid
        out.error = "Missing day"
        return out
    end if
    r = ApiFetchDayDetail(cfg, dayId.ToStr(), false)
    out.code = r.code
    if not r.ok
        out.error = r.error
        return out
    end if
    out.ok = true
    out.photos = PhotosFromDayJson(r.json)
    return out
end function

function JoinDayIds(batch as object) as string
    ids = ""
    for i = 0 to batch.Count() - 1
        if i > 0 then ids = ids + ","
        ids = ids + batch[i]
    end for
    return ids
end function

sub MergeDayBatch(cfg as object, batch as object, photos as object, maxPhotos as integer, reverse as boolean)
    ids = JoinDayIds(batch)
    r = ApiFetchDayDetail(cfg, ids, reverse)
    if not r.ok then return
    got = PhotosFromDayJson(r.json)
    for each p in got
        if photos.Count() >= maxPhotos then return
        photos.Push(p)
    end for
end sub
