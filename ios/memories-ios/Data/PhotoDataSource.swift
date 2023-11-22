//
//  PhotoDataSource.swift
//  memories-ios
//
//  Created by m-oehme on 19.11.23.
//

import Foundation
import GRDB

class PhotoDataSource {
    
    let databaseService: DatabaseService
    
    init(databaseService: DatabaseService) {
        self.databaseService = databaseService
    }
    
    func createTable() throws {
        try databaseService.dbQueue.write { db in
            try db.create(table: Photo.databaseTableName) { t in
                t.autoIncrementedPrimaryKey(PhotoColumns.id.rawValue)
                t.column(PhotoColumns.localId.rawValue, .integer)
                t.column(PhotoColumns.auid.rawValue, .text)
                t.column(PhotoColumns.buid.rawValue, .text)
                t.column(PhotoColumns.mtime.rawValue, .integer)
                t.column(PhotoColumns.dateTaken.rawValue, .integer)
                t.column(PhotoColumns.dayId.rawValue, .integer)
                t.column(PhotoColumns.baseName.rawValue, .text)
                t.column(PhotoColumns.bucketId.rawValue, .integer)
                t.column(PhotoColumns.bucketName.rawValue, .text)
                t.column(PhotoColumns.hasRemote.rawValue, .boolean)
                t.column(PhotoColumns.flag.rawValue, .integer)
            }
        }
    }
    
    func getDays(bucketIds: [String]) throws -> [Day] {
        return try databaseService.dbQueue.read { db in
            let statement = try db.makeStatement(sql: "SELECT dayid, COUNT(local_id) AS count FROM photos WHERE bucket_id IN (:bucketIds) AND has_remote = 0 GROUP BY dayid ORDER BY dayid DESC")
            return try Day.fetchAll(statement, arguments: [
                "bucketIds": bucketIds.joined(separator: ",")
            ])
        }
    }
    
    func getPhotosByDay(dayId: Int64, buckets: [String]) throws -> [Photo] {
        return try databaseService.dbQueue.read { db in
            let statement = try db.makeStatement(sql: "SELECT * FROM photos WHERE dayid=:dayId AND bucket_id IN (:buckets) AND has_remote = 0 ORDER BY date_taken DESC")
            return try Photo.fetchAll(statement, arguments: [
                "dayId": dayId,
                "bucketIds": buckets.joined(separator: ",")
            ])
        }
    }
    
    func deleteFileIds(fileIds: [Int64]) throws {
        try databaseService.dbQueue.write { db in
            let statement = try db.makeStatement(sql: "DELETE FROM photos WHERE local_id IN (:fileIds)")
            try statement.execute(arguments: [
                "fileIds": fileIds.map({ id in
                    String(id)
                }) .joined(separator: ",")
            ])
        }
    }
    
    func getPhotosByFileIds(fileIds: [Int64]) throws -> [Photo] {
        return try databaseService.dbQueue.read { db in
            let statement = try db.makeStatement(sql: "SELECT * FROM photos WHERE local_id IN (:fileIds)")
            return try Photo.fetchAll(statement, arguments: [
                "fileIds": fileIds.map({ id in
                    String(id)
                }) .joined(separator: ",")
            ])
        }
    }
    
    func getPhotosByAUIDs(auids: [String]) throws -> [Photo] {
        return try databaseService.dbQueue.read { db in
            let statement = try db.makeStatement(sql: "SELECT * FROM photos WHERE auid IN (:auids)")
            return try Photo.fetchAll(statement, arguments: [
                "auids": auids.joined(separator: ",")
            ])
        }
    }
    
    func flagAll() throws {
        try databaseService.dbQueue.write { db in
            let statement = try db.makeStatement(sql: "UPDATE photos SET flag=1")
            try statement.execute()
        }
    }
    
    func unflag(fileId: Int64) throws {
        try databaseService.dbQueue.write { db in
            let statement = try db.makeStatement(sql: "UPDATE photos SET flag=0 WHERE local_id=:fileId")
            try statement.execute(arguments: [
                "fileId": fileId
            ])
        }
    }
    
    func deleteFlagged() throws {
        try databaseService.dbQueue.write { db in
            let statement = try db.makeStatement(sql: "DELETE FROM photos WHERE flag=1")
            try statement.execute()
        }
    }
    
    func insert(photos: [Photo]) throws {
        try databaseService.dbQueue.write { db in
            for var photo in photos {
                try photo.insert(db)
            }
        }
    }
    
    func getBuckets() throws -> [Bucket] {
        return try databaseService.dbQueue.read { db in
            let statement = try db.makeStatement(sql: "SELECT bucket_id, bucket_name FROM photos GROUP BY bucket_id")
            return try Bucket.fetchAll(statement)
        }
    }
    
    func setHasRemote(auids: [String], buids: [String], v: Bool) throws {
        try databaseService.dbQueue.write { db in
            let statement = try db.makeStatement(sql: "UPDATE photos SET has_remote=:v WHERE auid IN (:auids) OR buid IN (:buids)")
            try statement.execute(arguments: [
                "v": v,
                "auids": auids.joined(separator: ","),
                "buids": buids.joined(separator: ",")
            ])
        }
    }
}

enum PhotoColumns : String, CodingKey, ColumnExpression {
    case id
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
