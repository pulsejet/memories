import sys
from timezonefinder import TimezoneFinder

def get_timezone(lat, lon):
    """
    Get timezone string from latitude and longitude coordinates.
    
    Args:
        lat: Latitude coordinate (float)
        lon: Longitude coordinate (float)
    
    Returns:
        Timezone string or empty string if not found
    """
    try:
        tf = TimezoneFinder(in_memory=True)
        timezone = tf.timezone_at(lat=float(lat), lng=float(lon))
        return timezone if timezone else ""
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return ""

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python findtimezone.py <latitude> <longitude>", file=sys.stderr)
        sys.exit(1)
    
    lat = sys.argv[1]
    lon = sys.argv[2]
    
    timezone = get_timezone(lat, lon)
    print(timezone)