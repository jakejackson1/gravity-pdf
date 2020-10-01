import React from 'react'
import PropTypes from 'prop-types'
import { connect } from 'react-redux'
import { getCustomFontList, resetSearchResult } from '../../actions/fontManager'

const FontListAlertMessage = ({ empty, error, getCustomFontList, resetSearchResult }) => {
  const fontListEmpty = <span>{GFPDF.fontListEmpty}</span>
  const searchResultEmpty = (
    <span>
      {GFPDF.searchResultEmpty} <span className='link' onClick={resetSearchResult}>Clear Search.</span>
    </span>
  )
  const apiError = <p className='link' onClick={getCustomFontList}>{error}</p>
  const displayContent = empty ? fontListEmpty : !error ? searchResultEmpty : apiError

  return (
    <div className='alert-message'>
      {displayContent}
    </div>
  )
}

FontListAlertMessage.propTypes = {
  empty: PropTypes.bool,
  error: PropTypes.string,
  getCustomFontList: PropTypes.func.isRequired,
  resetSearchResult: PropTypes.func.isRequired
}

export default connect(null, {
  getCustomFontList,
  resetSearchResult
})(FontListAlertMessage)
