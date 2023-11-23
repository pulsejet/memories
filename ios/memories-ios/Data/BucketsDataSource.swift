//
//  BucketsDataSource.swift
//  memories-ios
//
//  Created by m-oehme on 23.11.23.
//

import Foundation

class BucketsDataSource {
    private let defaults = UserDefaults.standard
    private let KEY = Storage.DEFAULTS_KEY + ".bucket_ids"
    
    func getEnabledBucketIds() -> [String] {
        return defaults.stringArray(forKey: KEY) ?? []
    }
    
    
    func setEnabledBucketIds(ids: [String]) {
        defaults.set(ids, forKey: KEY)
    }
}
