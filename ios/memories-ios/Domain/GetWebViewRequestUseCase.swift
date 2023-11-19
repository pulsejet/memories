//
//  GetWebViewRequestUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 14.11.23.
//

import Foundation

class GetWebViewRequestUseCase {
    
    let httpService: HttpService
    
    init(httpService: HttpService) {
        self.httpService = httpService
    }
    
    func build(subpath: String? = nil) throws -> URLRequest {
        guard var url = httpService.baseUrl else {
            throw WebViewRequestError.missingBaseUrl
        }
        guard let authHeader = httpService.authHeader else {
            throw WebViewRequestError.missingCredential
        }
        
        if subpath != nil {
            url += subpath!
        }
        
        guard let urlType = URL(string: url) else {
            throw WebViewRequestError.invalidUrl
        }
        
        var request = URLRequest(url: urlType)
        request.setValue(authHeader, forHTTPHeaderField: "Authorization")
        
        return request
    }
}

enum WebViewRequestError : Error {
    case missingBaseUrl
    case missingCredential
    case invalidUrl
}
