//
//  DatabaseService.swift
//  memories-ios
//
//  Created by m-oehme on 21.11.23.
//

import Foundation
import GRDB

class DatabaseService {
    var dbQueue: DatabaseQueue!
    
    func setup() throws {
        let databaseURL = try FileManager.default
            .url(for: .applicationDirectory, in: .userDomainMask, appropriateFor: nil, create: true)
            .appendingPathComponent("db.sqlite")
        
        dbQueue = try DatabaseQueue(path: databaseURL.path)
    }
}
