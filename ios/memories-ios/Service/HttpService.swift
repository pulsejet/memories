//
//  HttpService.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//

import Foundation
import Alamofire

class HttpService {
    
    static let userAgent = "Memories"
    private (set) var authHeader: String? = nil
    private (set) var baseUrl: String? = nil
    private (set) var trustAll = false
    
    
    func build(url: String?, trustAll: Bool) {
        self.baseUrl = url
        self.trustAll = trustAll
    }
    
    func setAuthHeader(username: String?, token: String?) {
        if username == nil || token == nil {
            authHeader = nil
            return
        }
        
        let auth = username! + ":" + token!
        authHeader = "Basic " + auth.toBase64()
    }
    
    func postRequestFormEncoded<T>(url: String, parameters: Parameters? = nil, type: T.Type) async -> HttpResult<T> {
        
        let headers: HTTPHeaders = [
            .contentType("application/x-www-form-urlencoded"),
            .userAgent(HttpService.userAgent)
        ]
        
        return await postRequest(url: url, parameters: parameters, headers: headers, type: type)
    }
    
    func postRequest<T>(url: String, parameters: Parameters? = nil, type: T.Type) async -> HttpResult<T> {
        
        let headers: HTTPHeaders = [
            .contentType("application/json"),
            .userAgent(HttpService.userAgent)
        ]
        
        return await postRequest(url: url, headers: headers, type: type)
    }
    
    private func postRequest<T>(url: String, parameters: Parameters? = nil, headers: HTTPHeaders, type: T.Type) async -> HttpResult<T> {
        
        return await AF.request(url, method: .post, parameters: parameters, headers: headers)
            .validate()
            .serializingDecodable(type)
            .response
            .parseResponse()
    }
    
    func getRequest<T>(url: String, type: T.Type) async -> HttpResult<T> where T: Decodable {
        
        let headers: HTTPHeaders = [
            .contentType("application/json"),
            .userAgent(HttpService.userAgent)
        ]
        
        return await AF.request(url, method: .get, headers: headers)
            .validate()
            .serializingDecodable(type)
            .response
            .parseResponse()
    }
}

extension DataResponse {
    func parseResponse() async -> HttpResult<Success> where Success: Decodable {
        
        let response = self
        
        let statusCode = response.response?.statusCode ?? -1
        debugPrint(response.request?.httpMethod ?? "", response.request?.url ?? "")
        switch response.result {
        case .success(let value):
            debugPrint(statusCode, value)
            return .success(statusCode: statusCode, data: value)
        case .failure(let error):
            debugPrint(statusCode, error)
            return .failure(statusCode: statusCode, error: error)
        }
    }
}

enum HttpResult<T: Decodable> {
    
    case success(statusCode: Int, data: T)
    case failure(statusCode: Int, error: Error?)
}
