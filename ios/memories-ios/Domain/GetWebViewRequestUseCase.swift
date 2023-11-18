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
    
    func build(subpath: String? = nil) -> URLRequest? {
        guard var url = httpService.baseUrl else { return nil }
        guard let authHeader = httpService.authHeader else { return nil }
        
        if subpath != nil {
            url += subpath!
        }
        
        guard let urlType = URL(string: url) else { return nil }
        
        var request = URLRequest(url: urlType)
        request.setValue(authHeader, forHTTPHeaderField: "Authorization")
        
        return request
    }
}
