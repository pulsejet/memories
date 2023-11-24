//
//  BuildBusFunctionUseCase.swift
//  memories-ios
//
//  Created by Max on 23.11.23.
//

import Foundation

class BuildBusFunctionUseCase {
    
    func invoke(event: String, data: String = "null") -> String {
        return "window._nc_event_bus?.emit('\(event)', \(data))"
    }
}
