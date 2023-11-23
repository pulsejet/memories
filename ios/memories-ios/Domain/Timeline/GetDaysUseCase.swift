//
//  GetDaysUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 23.11.23.
//

import Foundation

class GetDaysUseCase {
    
    let photoDataSource: PhotoDataSource
    let bucketsDataSource: BucketsDataSource
    
    init(photoDataSource: PhotoDataSource, bucketsDataSource: BucketsDataSource) {
        self.photoDataSource = photoDataSource
        self.bucketsDataSource = bucketsDataSource
    }
    
    func invoke() -> [[String: Any]] {
        let bucketIds = bucketsDataSource.getEnabledBucketIds()
        
        do {
            let days = try photoDataSource.getDays(bucketIds: bucketIds)
            return try days.toDictionaryArray()
        } catch(let error) {
            debugPrint("Error fetching Days: ", error)
            return [[:]]
        }
    }
}
