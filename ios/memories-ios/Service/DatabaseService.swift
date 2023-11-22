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
        try migrator.migrate(dbQueue)
    }
    
    var migrator: DatabaseMigrator {
        var migrator = DatabaseMigrator()
        
        migrator.registerMigration("v0") { db in
            try db.create(table: "photos") { t in
                t.autoIncrementedPrimaryKey("id")
                t.column("local_id", .integer)
                t.column("auid", .text)
                t.column("buid", .text)
                t.column("mtime", .integer)
                t.column("date_taken", .integer)
                t.column("dayid", .integer)
                t.column("basename", .text)
                t.column("bucket_id", .integer)
                t.column("bucket_name", .text)
                t.column("has_remote", .boolean)
                t.column("flag", .integer)
            }
        }
        
#if DEBUG
        migrator.eraseDatabaseOnSchemaChange = true
#endif
        
        return migrator
    }
}
