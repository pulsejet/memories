//
//  NativeX.swift
//  memories-ios
//
//  Created by m-oehme on 11.11.23.
//

import Foundation
import WebKit


class NativeXRequestHandler {
    
    let getDaysUseCase: GetDaysUseCase
    
    init(getDaysUseCase: GetDaysUseCase) {
        self.getDaysUseCase = getDaysUseCase
    }
    
    func handleUrlRequest(urlRequest: UrlRequest) -> Any? {
        
        if (urlRequest.url.match(regEx: DAYS)) {
            debugPrint("Match", DAYS)
            return getDaysUseCase.invoke()
        }
        
        return nil
    }
    
    
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
    
    
    
    let LOGIN = try! NSRegularExpression(pattern: "^/api/login/.+$")

    let DAYS = try! NSRegularExpression(pattern: "^/api/days$")
    let DAY = try! NSRegularExpression(pattern: "^/api/days/\\d+$")

    let IMAGE_INFO = try! NSRegularExpression(pattern: "^/api/image/info/\\d+$")
    let IMAGE_DELETE = try! NSRegularExpression(pattern: "^/api/image/delete/[0-9a-f]+(,[0-9a-f]+)*$")
    
    let IMAGE_PREVIEW = try! NSRegularExpression(pattern: "^/image/preview/\\d+$")
    let IMAGE_FULL = try! NSRegularExpression(pattern: "^/image/full/[0-9a-f]+$")

    let SHARE_URL = try! NSRegularExpression(pattern: "^/api/share/url/.+$")
    let SHARE_BLOB = try! NSRegularExpression(pattern: "^/api/share/blob/.+$")
    let SHARE_LOCAL = try! NSRegularExpression(pattern: "^/api/share/local/[0-9a-f]+$")

    let CONFIG_ALLOW_MEDIA = try! NSRegularExpression(pattern: "^/api/config/allow_media/\\d+$")
}
