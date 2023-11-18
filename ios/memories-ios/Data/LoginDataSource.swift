//
//  LoginDataSource.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//

import Foundation

class LoginDataSource {
    
    let httpService: HttpService
    var timer: Timer? = nil
    
    init(httpService: HttpService) {
        self.httpService = httpService
    }
    
    
    func login(loginFlowUrl: String) async throws -> LoginDescription  {
        let result = await httpService.postRequest(url: loginFlowUrl, type: LoginDescription.self)
        
        switch result {
        case .success(_, let data):
            return data
        case .failure(_, let error):
            throw LoginError.loginFailed
        }
    }
    
    func polling(poll: LoginDescription.Poll) async throws -> LoginResult? {
        var pollCount = 0
        while pollCount < 10 * 60 {
            pollCount += 3
            
            debugPrint("Poll Login: " + poll.endpoint)
            try await Task.sleep(nanoseconds: 3000000000)
            
            let parameters = ["token": poll.token]
            let response = await httpService.postRequestFormEncoded(url: poll.endpoint, parameters: parameters, type: LoginResult.self)
            
            switch response {
            case .success(_, let data):
                return data
            case .failure(_, _):
                continue
            }
        }
        return nil
    }
}

enum LoginError : Error {
    case loginFailed
}

struct LoginDescription : Codable {
    
    let poll: Poll
    let login: String
    
    struct Poll : Codable {
        
        let token: String
        let endpoint: String
    }
}

struct LoginResult : Codable {
    
    let server: String
    let loginName: String
    let appPassword: String
}
