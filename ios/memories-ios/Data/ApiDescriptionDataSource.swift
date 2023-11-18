//
//  GetApiDescriptionUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//

import Foundation

class ApiDescriptionDataSource {
    
    let httpService: HttpService
    
    init(httpService: HttpService) {
        self.httpService = httpService
    }
    
    func invoke(baseUrl: String) async throws -> ApiDescription {
        
        let url = baseUrl + "api/describe"
        
        let result = await httpService.getRequest(url: url, type: ApiDescription.self)
        
        switch result {
        case .success(_, let data):
            return data
        case .failure(_, let error):
            throw error!
        }
    }
}

struct ApiDescription : Codable {
    
    let version: String
    let baseUrl: String
    let loginFlowUrl: String
    let uid: String?
}
