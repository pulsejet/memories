//
//  GetLocalFoldersUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 23.11.23.
//

import Foundation

class GetLocalFoldersUseCase {
    
    let photosDataSource: PhotoDataSource
    
    init(photosDataSource: PhotoDataSource) {
        self.photosDataSource = photosDataSource
    }
    
    func invoke() throws -> [[String: Any]] {
        let buckets = try photosDataSource.getBuckets()
        return try buckets.toDictionaryArray()
    }
}
