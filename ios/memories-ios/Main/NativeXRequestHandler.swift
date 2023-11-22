//
//  NativeX.swift
//  memories-ios
//
//  Created by m-oehme on 11.11.23.
//

import Foundation
import WebKit


class NativeXRequestHandler {
    
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
