package config

import (
	"errors"
)

type TCfg struct {
	ChunkSize int `json:"chunkSize" validate:"gte=1"`
	QF        int `json:"qf"`

	VAAPI         bool   `json:"vaapi"`
	VAAPILowPower bool   `json:"vaapiLowPower"`
	VAAPIDevice   string `json:"vaapiDevice"`

	NVENC           bool   `json:"nvenc"`
	NVENCTemporalAQ bool   `json:"nvencTemporalAQ"`
	NVENCScale      string `json:"nvencScale" validate:"required_if=NVENC true,omitempty,oneof=cuda npp"`

	UseTranspose     bool `json:"useTranspose"`
	ForceSwTranspose bool `json:"forceSwTranspose"`
	UseGopSize       bool `json:"useGopSize"`
}

func (c *TCfg) Validate() error {
	if err := validate.Struct(c); err != nil {
		return err
	}
	if c.VAAPI && c.NVENC {
		return errors.New("vaapi and nvenc are mutually exclusive")
	}
	return nil
}
