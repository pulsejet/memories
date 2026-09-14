package api

import (
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/pulsejet/memories/go-vod/core"
	"github.com/stretchr/testify/require"
)

func TestMasterPlaylist(t *testing.T) {
	got, err := MasterPlaylist([]core.Rendition{
		{Quality: "1080p", Width: 1920, Height: 1080, Bitrate: 1000},
		{Quality: "max", Width: 1920, Height: 1080, Bitrate: 5000000, Order: 1},
		{Quality: "480p", Width: 854, Height: 480, Bitrate: 400},
	}, 30, "?t=123")
	require.NoError(t, err)
	require.Less(t, strings.Index(got, "480p.m3u8"), strings.Index(got, "1080p.m3u8"))
	require.Less(t, strings.Index(got, "1080p.m3u8"), strings.Index(got, "max.m3u8"))
	require.Contains(t, got, "BANDWIDTH=400,RESOLUTION=854x480")
	require.Contains(t, got, "480p.m3u8?t=123")
}

func TestVariantPlaylist(t *testing.T) {
	got, err := VariantPlaylist("720p", 62*time.Second+500*time.Millisecond, 3, "?t=123")
	require.NoError(t, err)
	require.Equal(t, 21, strings.Count(got, "#EXTINF"))
	require.Contains(t, got, "#EXTINF:2.500,nodesc\n720p-000020.ts?t=123")
	require.Contains(t, got, "#EXT-X-TARGETDURATION:3")
	require.Contains(t, got, "#EXT-X-PLAYLIST-TYPE:VOD")
	require.True(t, strings.HasSuffix(strings.TrimSpace(got), "#EXT-X-ENDLIST"))
}

func TestVariantPlaylistEmpty(t *testing.T) {
	got, err := VariantPlaylist("720p", 0, 3, "")
	require.NoError(t, err)
	require.NotContains(t, got, "#EXTINF")
	require.Contains(t, got, "#EXT-X-ENDLIST")
}

func TestQueryString(t *testing.T) {
	r := httptest.NewRequest("GET", "/x?a=1&b=2", nil)
	require.Equal(t, "?a=1&b=2", QueryString(r))
	require.Equal(t, "", QueryString(httptest.NewRequest("GET", "/x", nil)))
}
