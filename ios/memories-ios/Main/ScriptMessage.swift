//
//  ScriptMessage.swift
//  memories-ios
//
//  Created by m-oehme on 19.11.23.
//

import Foundation

struct ScriptMessage : Decodable {
    
    private enum CodingKeys: String, CodingKey {
        case method = "method"
        case parameter = "parameter"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        method = try container.decode(Method.self, forKey: .method)

        switch method {
        case .setThemeColor:
            parameter = try container.decode(ThemeColor.self, forKey: .parameter)
        case .toast:
            parameter = try container.decode(Toast.self, forKey: .parameter)
        case .downloadFromUrl:
            parameter = try container.decode(DownloadFromUrl.self, forKey: .parameter)
        case .playVideo:
            parameter = try container.decode(PlayVideo.self, forKey: .parameter)
        case .destroyVideo:
            parameter = try container.decode(DestroyVideo.self, forKey: .parameter)
        case .configSetLocalFolders:
            parameter = try container.decode(ConfigSetLocalFolders.self, forKey: .parameter)
        case .setHasRemote:
            parameter = try container.decode(SetHasRemote.self, forKey: .parameter)
        case .printLog:
            parameter = try container.decode(PrintLog.self, forKey: .parameter)
        default: parameter = nil
        }
    }
    
    let method: Method
    let parameter: ScriptParameters?
    
    enum Method : String, Decodable {
        case isNative, setThemeColor, playTouchSound, toast, logout, reload, downloadFromUrl, playVideo, destroyVideo, configSetLocalFolders, configGetLocalFolders, configHasMediaPermission, getSyncStatus, setHasRemote, printLog
    }
}

protocol ScriptParameters : Decodable {}

struct ThemeColor : ScriptParameters {
    
    let color: String
    let isDark: Bool
}

struct Toast : ScriptParameters {
    
    let message: String
    let long: Bool?
}

struct DownloadFromUrl : ScriptParameters {
    
    let url: String
    let filename: String
}

struct PlayVideo : ScriptParameters {
    
    let auid: String
    let fileid: Int
    let urlArray: String
}

struct DestroyVideo : ScriptParameters {
    
    let fileid: Int
}

struct ConfigSetLocalFolders : ScriptParameters {
    
    let json: String
}

struct SetHasRemote : ScriptParameters {
    
    let auids: String
    let buids: String
    let value: Bool
}

struct PrintLog : ScriptParameters {
    
    let message: String
}
