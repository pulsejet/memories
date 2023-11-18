//
//  NativeX.swift
//  memories-ios
//
//  Created by m-oehme on 11.11.23.
//

import Foundation
import WebKit


class NativeX {
    
    let LOGIN = "^/api/login/.+$"

    let DAYS = "^/api/days$"
    let DAY = "^/api/days/\\d+$"

    let IMAGE_INFO = "^/api/image/info/\\d+$"
    let IMAGE_DELETE = "^/api/image/delete/[0-9a-f]+(,[0-9a-f]+*$"
    
    let IMAGE_PREVIEW = try! NSRegularExpression(pattern: "^/image/preview/\\d+$")
    let IMAGE_FULL = try! NSRegularExpression(pattern: "^/image/full/[0-9a-f]+$")

    let SHARE_URL = "^/api/share/url/.+$"
    let SHARE_BLOB = "^/api/share/blob/.+$"
    let SHARE_LOCAL = "^/api/share/local/[0-9a-f]+$"

    let CONFIG_ALLOW_MEDIA = "^/api/config/allow_media/\\d+$"
    
//    private var themeStored = false
    
    func isNative() -> Bool {
        return true
    }
    
//    func setThemeColor(color: String?, isDark: Bool) {
//        // Save for getting it back on next start
//        if (!themeStored && http.isLoggedIn()) {
//            themeStored = true
//            mCtx.storeTheme(color, isDark);
//        }
//
//        // Apply the theme
//        mCtx.runOnUiThread {
//            mCtx.applyTheme(color, isDark)
//        }
//    }
//
//    func playTouchSound() {
//        mCtx.runOnUiThread {
//            mCtx.binding.webview.playSoundEffect(SoundEffectConstants.CLICK)
//        }
//    }
//
//    func toast(message: String, long: Bool = false) {
//        mCtx.runOnUiThread {
//            val duration = if (long) Toast.LENGTH_LONG else Toast.LENGTH_SHORT
//                                Toast.makeText(mCtx, message, duration).show()
//        }
//    }
//
//    func logout() {
//        account.loggedOut()
//    }
//
//    func reload() {
//        mCtx.runOnUiThread {
//            mCtx.loadDefaultUrl()
//        }
//    }
//
//    func downloadFromUrl(url: String?, filename: String?) {
//        if (url == nil || filename == nil) return;
//        dlService!!.queue(url, filename)
//    }
//
//    func playVideo(auid: String, fileid: Long, urlsArray: String) {
//        mCtx.threadPool.submit {
//            // Get URI of remote videos
//            let urls = JSONArray(urlsArray)
//            let list = Array(urls.length()) {
//                Uri.parse(urls.getString(it))
//            }
//
//            // Get URI of local video
//            let videos = query.getSystemImagesByAUIDs(arrayListOf(auid))
//
//            // Play with exoplayer
//            mCtx.runOnUiThread {
//                if (!videos.isEmpty()) {
//                    mCtx.initializePlayer(arrayOf(videos[0].uri), fileid)
//                } else {
//                    mCtx.initializePlayer(list, fileid)
//                }
//            }
//        }
//    }
//
//    func destroyVideo(fileid: Int64) {
//        mCtx.runOnUiThread {
//            mCtx.destroyPlayer(fileid)
//        }
//    }
//
//    func configSetLocalFolders(json: String?) {
//        if (json == null) return;
//        query.localFolders = JSONArray(json)
//    }
//
//    func configGetLocalFolders() -> String {
//        return query.localFolders.toString()
//    }
//
//    func configHasMediaPermission() -> Bool {
//        return permissions.hasAllowMedia() && permissions.hasMediaPermission()
//    }
//
//    func getSyncStatus() -> Int {
//        return query.syncStatus
//    }
//
//    func setHasRemote(auids: String, buids: String, value: Bool) {
//        Log.v(TAG, "setHasRemote: auids=$auids, buids=$buids, value=$value")
//        mCtx.threadPool.submit {
//            val auidArray = JSONArray(auids)
//            val buidArray = JSONArray(buids)
//            query.setHasRemote(
//                List(auidArray.length()) { auidArray.getString(it) },
//                List(buidArray.length()) { buidArray.getString(it) },
//                value
//            )
//        }
//    }
    
    func handleRequest(request: URLRequest) -> URLRequest? {
        guard let path = request.url?.path else {
            return nil
        }
        
        var response: URLRequest? = request
        if request.httpMethod == "GET" {
            response = routerGet(request: request)
        }
        
        // Allow CORS from all origins
        response?.setValue("*", forHTTPHeaderField: "Access-Control-Allow-Origin")
        response?.setValue("*", forHTTPHeaderField: "Access-Control-Allow-Headers")
        
        let range = NSRange(location: 0, length: path.utf16.count)
        
        // Cache image responses for 7 days
        if ((IMAGE_PREVIEW.firstMatch(in: path, range: range) != nil) || (IMAGE_FULL.firstMatch(in: path, range: range) != nil)) {
            response?.setValue("max-age=604800", forHTTPHeaderField: "Cache-Control")
        }
        
        return response
    }
    
    func routerGet(request: URLRequest) -> URLRequest? {
        
        return nil
    }
}
