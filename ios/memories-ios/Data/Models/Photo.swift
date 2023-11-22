//
//  Photo.swift
//  memories-ios
//
//  Created by m-oehme on 22.11.23.
//

import Foundation
import GRDB

private enum PhotoColumns : String, CodingKey, ColumnExpression {
    case id = "id"
    case localId = "local_id"
    case auid = "auid"
    case buid = "buid"
    case mtime = "mtime"
    case dateTaken = "date_taken"
    case dayId = "dayid"
    case baseName = "basename"
    case bucketId = "bucket_id"
    case bucketName = "bucket_name"
    case hasRemote = "has_remote"
    case flag = "flag"
}

struct Photo {
    
    let id: Int?
    let localId: Int64
    let auid: String
    let buid: String
    let mtime: Int64
    let dateTaken: Int64
    let dayId: Int64
    let baseName: String
    let bucketId: Int64
    let bucketName: String
    let hasRemote: Bool
    let flag: Int
}

extension Photo: Codable, TableRecord, FetchableRecord, MutablePersistableRecord {
    
    static let databaseTableName = "photos"
    
    init(row: Row) throws {
        id = row[PhotoColumns.id]
        localId = row[PhotoColumns.localId]
        auid = row[PhotoColumns.auid]
        buid = row[PhotoColumns.buid]
        mtime = row[PhotoColumns.mtime]
        dateTaken = row[PhotoColumns.dateTaken]
        dayId = row[PhotoColumns.dayId]
        baseName = row[PhotoColumns.baseName]
        bucketId = row[PhotoColumns.bucketId]
        bucketName = row[PhotoColumns.bucketName]
        hasRemote = row[PhotoColumns.hasRemote]
        flag = row[PhotoColumns.flag]
    }
    
    func encode(to container: inout PersistenceContainer) throws {
        container[PhotoColumns.id] = id
        container[PhotoColumns.localId] = localId
        container[PhotoColumns.auid] = auid
        container[PhotoColumns.buid] = buid
        container[PhotoColumns.mtime] = mtime
        container[PhotoColumns.dateTaken] = dateTaken
        container[PhotoColumns.dayId] = dayId
        container[PhotoColumns.baseName] = baseName
        container[PhotoColumns.bucketId] = bucketId
        container[PhotoColumns.bucketName] = bucketName
        container[PhotoColumns.hasRemote] = hasRemote
        container[PhotoColumns.flag] = flag
    }
}

struct Bucket : Codable, FetchableRecord {
    init(row: Row) throws {
        id = row[PhotoColumns.bucketId]
        name = row[PhotoColumns.bucketName]
    }
    
    let id: String
    let name: String
}


struct Day : Codable, FetchableRecord {
    init(row: Row) throws {
        dayId = row[PhotoColumns.dayId]
        count = row["count"]
    }
    
    let dayId: Int64
    let count: Int64
}
