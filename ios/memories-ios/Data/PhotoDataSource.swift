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
